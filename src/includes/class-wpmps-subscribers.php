<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Subscribers {
  public static function get_subscribers(){
    // Usar versión optimizada con caché y límite de llamadas MP
    return self::get_subscribers_fast();
  }

  public static function get_subscribers_fast($use_cache = true, $limit_mp_calls = 10, $get_all_preapprovals = false) {
    // Intentar obtener datos del caché con sistema persistente
    if ($use_cache) {
      $cached_data = self::get_persistent_cache();
      if ($cached_data !== false) {
        if (function_exists('wpmps_log_admin')){
          wpmps_log_admin('get_subscribers_from_cache', [
            'count' => count($cached_data),
            'source' => 'persistent_cache'
          ]);
        }
        return $cached_data;
      }
    }
    
    $start_time = microtime(true);
    $rows = [];
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    
    // Log start
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_subscribers_optimized_start', [
        'has_token' => !empty($token),
        'limit_mp_calls' => $limit_mp_calls,
        'use_cache' => $use_cache
      ]);
    }
    
    // Obtener usuarios de WordPress
    $args = [
      'meta_query' => [
        'relation' => 'OR',
        [ 'key' => '_mp_preapproval_id', 'compare' => 'EXISTS' ],
        [ 'key' => '_suscripcion_activa', 'compare' => 'EXISTS' ],
      ],
      'fields' => ['ID','user_email'],
      'number' => 500,
    ];
    $users = get_users($args);
    
    // Inicializar cliente MP si tenemos token
    $client = null;
    if ($token) {
      try {
        $client = new WPMPS_MP_Client($token);
      } catch (\Throwable $e) {
        if (function_exists('wpmps_log_error')){
          wpmps_log_error('subscribers', 'mp_client_error', $e->getMessage());
        }
      }
    }
    
    $mp_calls_made = 0;
    $mp_start_time = microtime(true);
    
    // Priorizar usuarios: primero los que necesitan actualización urgente
    $priority_users = [];
    $regular_users = [];
    
    foreach ($users as $u) {
      $updated_at = get_user_meta($u->ID, '_mp_updated_at', true);
      $is_old = empty($updated_at) || (strtotime($updated_at) < (time() - 3600)); // Más de 1 hora
      
      if ($is_old) {
        $priority_users[] = $u;
      } else {
        $regular_users[] = $u;
      }
    }
    
    // Procesar usuarios prioritarios primero, luego regulares
    $all_users = array_merge($priority_users, $regular_users);
    
    foreach ($all_users as $u) {
      $user_obj = get_user_by('ID', $u->ID);
      $preapproval_id = get_user_meta($u->ID, '_mp_preapproval_id', true);
      $plan_id = get_user_meta($u->ID, '_mp_plan_id', true);
      $plan_name = get_user_meta($u->ID, '_mp_plan_name', true);
      $updated_at = get_user_meta($u->ID, '_mp_updated_at', true);
      
      // Check if user has subscriber role
      $user_roles = $user_obj ? $user_obj->roles : [];
      $is_subscriber = in_array('subscriber', $user_roles);
      
      // Initialize row with WP data
      $row = [
        'user_id'        => $u->ID,
        'email'          => $u->user_email,
        'user_roles'     => $user_roles,
        'is_subscriber'  => $is_subscriber,
        'sync_status'    => 'unknown',
        'preapproval_id' => $preapproval_id,
        'plan_id'        => $plan_id,
        'plan_name'      => $plan_name,
        'status'         => 'inactive',
        'reason'         => '',
        'amount'         => '',
        'currency'       => '',
        'frequency'      => '',
        'frequency_type' => '',
        'date_created'   => '',
        'updated_at'     => $updated_at,
        'token_status'   => 'unknown',
        'cache_status'   => 'fresh', // Nuevo campo para indicar si viene de caché
      ];
      
      // Solo hacer llamada a MP si:
      // 1. Tenemos cliente y preapproval_id
      // 2. No hemos excedido el límite de llamadas
      // 3. Los datos están desactualizados (más de 1 hora) O es usuario prioritario
      $should_call_mp = $client && 
                       $preapproval_id && 
                       $mp_calls_made < $limit_mp_calls;
      
      if ($should_call_mp) {
        try {
          $resp = $client->get_preapproval($preapproval_id);
          $mp_calls_made++;
          
          if (($resp['http'] ?? 0) === 200 && !empty($resp['body'])) {
            // Actualizar con datos frescos de MP
            $row['token_status'] = 'current_token';
            $row['cache_status'] = 'fresh_from_mp';
            
            $item = $resp['body'];
            $auto = is_array($item['auto_recurring'] ?? null) ? $item['auto_recurring'] : [];
            $mp_status = sanitize_text_field($item['status'] ?? '');
            
            $row['status'] = $mp_status;
            $row['reason'] = sanitize_text_field($item['reason'] ?? '');
            $row['amount'] = isset($auto['transaction_amount']) ? floatval($auto['transaction_amount']) : '';
            $row['currency'] = sanitize_text_field($auto['currency_id'] ?? '');
            $row['frequency'] = isset($auto['frequency']) ? intval($auto['frequency']) : '';
            $row['frequency_type'] = sanitize_text_field($auto['frequency_type'] ?? '');
            $row['date_created'] = sanitize_text_field($item['date_created'] ?? '');
            $row['updated_at'] = sanitize_text_field($item['last_modified'] ?? ($item['date_created'] ?? $updated_at));
            
            // Actualizar caché local del usuario
            update_user_meta($u->ID, '_mp_updated_at', current_time('mysql'));
            update_user_meta($u->ID, '_mp_status_cache', $mp_status);
            update_user_meta($u->ID, '_mp_amount_cache', $row['amount']);
            update_user_meta($u->ID, '_mp_currency_cache', $row['currency']);
            
            // Determinar sync status
            $mp_active = ($mp_status === 'authorized');
            if ($mp_active && $is_subscriber) {
              $row['sync_status'] = 'ok';
            } elseif (!$mp_active && $is_subscriber) {
              $row['sync_status'] = 'needs_role_change';
            } else {
              $row['sync_status'] = 'irrelevant';
            }
            
          } else {
            $row['token_status'] = 'different_token';
            $row['sync_status'] = 'different_token';
            $row['cache_status'] = 'different_token';
          }
          
        } catch (\Throwable $e) {
          $row['token_status'] = 'error';
          $row['sync_status'] = 'error';
          $row['cache_status'] = 'error';
        }
      } else {
        // Usar datos cacheados del usuario si están disponibles
        $cached_status = get_user_meta($u->ID, '_mp_status_cache', true);
        $cached_amount = get_user_meta($u->ID, '_mp_amount_cache', true);
        $cached_currency = get_user_meta($u->ID, '_mp_currency_cache', true);
        
        if ($cached_status) {
          $row['status'] = $cached_status;
          $row['amount'] = $cached_amount;
          $row['currency'] = $cached_currency;
          $row['token_status'] = 'current_token';
          $row['cache_status'] = 'from_user_cache';
          
          // Determinar sync status basado en caché
          $mp_active = ($cached_status === 'authorized');
          if ($mp_active && $is_subscriber) {
            $row['sync_status'] = 'ok';
          } elseif (!$mp_active && $is_subscriber) {
            $row['sync_status'] = 'needs_role_change';
          } else {
            $row['sync_status'] = 'irrelevant';
          }
        } else {
          // Sin datos de caché, marcar como pendiente de actualización
          if ($preapproval_id && !$client) {
            $row['token_status'] = 'no_client';
            $row['sync_status'] = 'no_token';
          } elseif (!$preapproval_id) {
            $row['token_status'] = 'no_preapproval';
            $row['sync_status'] = 'no_preapproval';
          } else {
            $row['sync_status'] = 'pending_update';
            $row['cache_status'] = 'needs_update';
          }
        }
      }
      
      $rows[] = $row;
    }
    
    $mp_time = microtime(true) - $mp_start_time;
    $total_time = microtime(true) - $start_time;
    
    // Log performance
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_subscribers_optimized_complete', [
        'total_users' => count($users),
        'mp_calls_made' => $mp_calls_made,
        'mp_time' => round($mp_time, 2),
        'total_time' => round($total_time, 2),
        'avg_mp_time' => $mp_calls_made > 0 ? round($mp_time / $mp_calls_made, 2) : 0
      ]);
    }
    
    // Guardar en caché persistente si se usó caché
    if ($use_cache) {
      self::set_persistent_cache($rows, 900); // 15 minutos
    }
    
    return $rows;
  }

  /**
   * Obtiene todas las suscripciones de MercadoPago (no solo las de WordPress)
   */
  public static function get_all_mp_preapprovals($use_cache = true) {
    // Intentar obtener datos del caché
    if ($use_cache) {
      $cached_data = get_transient('wpmps_all_preapprovals_cache');
      if ($cached_data !== false) {
        if (function_exists('wpmps_log_admin')){
          wpmps_log_admin('get_all_preapprovals_from_cache', [
            'count' => count($cached_data),
            'source' => 'transient_cache'
          ]);
        }
        return $cached_data;
      }
    }
    
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (!$token) {
      return [];
    }
    
    try {
      $all_preapprovals = [];
      $limit = 50;
      $offset = 0;
      $total_fetched = 0;
      
      do {
        $url = "https://api.mercadopago.com/preapproval/search?" . http_build_query([
          'limit' => $limit,
          'offset' => $offset
        ]);
        
        $response = wp_remote_get($url, [
          'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
          ],
          'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
          break;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $response = [
          'http' => $http_code,
          'body' => $data
        ];
        
        if (($response['http'] ?? 0) !== 200 || empty($response['body']['results'])) {
          break;
        }
        
        $results = $response['body']['results'];
        $all_preapprovals = array_merge($all_preapprovals, $results);
        
        $total_fetched += count($results);
        $offset += $limit;
        
        // Evitar bucle infinito
        if (count($results) < $limit) {
          break;
        }
        
        // Límite de seguridad
        if ($total_fetched >= 500) {
          break;
        }
        
      } while (true);
      
      // Guardar en caché por 30 minutos
      if ($use_cache && !empty($all_preapprovals)) {
        set_transient('wpmps_all_preapprovals_cache', $all_preapprovals, 1800);
      }
      
      if (function_exists('wpmps_log_admin')){
        wpmps_log_admin('get_all_preapprovals_complete', [
          'total_preapprovals' => count($all_preapprovals),
          'total_fetched' => $total_fetched
        ]);
      }
      
      return $all_preapprovals;
      
    } catch (\Throwable $e) {
      if (function_exists('wpmps_log_error')){
        wpmps_log_error('subscribers', 'get_all_preapprovals_error', $e->getMessage());
      }
      return [];
    }
  }

  public static function refresh_subscriber($user_id){
    $pre_id = get_user_meta($user_id, '_mp_preapproval_id', true);
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('refresh_subscriber_start', ['user_id'=>intval($user_id),'preapproval_id'=>$pre_id]);
    }
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (!$pre_id || !$token) return false;
    $client = new WPMPS_MP_Client($token);
    $resp = $client->get_preapproval($pre_id);
    if ($resp['http'] !== 200) return false;
    $pre = $resp['body'];
    $status = sanitize_text_field($pre['status'] ?? '');
    $active = ($status === 'authorized') ? 'yes' : 'no';
    update_user_meta($user_id, '_suscripcion_activa', $active);
    if (!empty($pre['preapproval_plan_id'])) update_user_meta($user_id, '_mp_plan_id', sanitize_text_field($pre['preapproval_plan_id']));
    update_user_meta($user_id, '_mp_updated_at', current_time('mysql'));
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('refresh_subscriber_done', ['user_id'=>intval($user_id),'status'=>$status]);
    }
    return true;
  }

  public static function change_to_pending_role($user_id, $reason = 'mp_inactive'){
    $user_id = intval($user_id);
    if ($user_id <= 0) return false;
    
    $user = get_user_by('ID', $user_id);
    if (!$user) return false;
    
    // Change role from subscriber to pending (or whatever role represents pending)
    $user->remove_role('subscriber');
    $user->add_role('pending'); // Adjust this role name as needed
    
    // Update metadata
    update_user_meta($user_id, '_suscripcion_activa', 'no');
    update_user_meta($user_id, '_mp_updated_at', current_time('mysql'));
    
    // Log the action
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('change_to_pending_role', [
        'user_id' => $user_id,
        'reason' => $reason
      ]);
    }
    
    return true;
  }

  // Métodos de caché persistente
  public static function set_persistent_cache($data, $ttl = 900) {
    // Guardar en transient principal
    set_transient('wpmps_subscribers_cache', $data, $ttl);
    
    // Guardar backup en option (no expira)
    update_option('wpmps_subscribers_backup', [
      'data' => $data,
      'timestamp' => time(),
      'ttl' => $ttl
    ], false);
    
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('subscribers_cache_saved', [
        'count' => count($data),
        'ttl_minutes' => $ttl / 60
      ]);
    }
  }
  
  public static function get_persistent_cache($max_age = 1800) {
    // Intentar transient primero
    $cache = get_transient('wpmps_subscribers_cache');
    if ($cache !== false) {
      if (function_exists('wpmps_log_admin')){
        wpmps_log_admin('cache_hit_transient', ['count' => count($cache)]);
      }
      return $cache;
    }
    
    // Fallback a option backup
    $backup = get_option('wpmps_subscribers_backup');
    if ($backup && isset($backup['data'], $backup['timestamp'])) {
      $age = time() - $backup['timestamp'];
      if ($age < $max_age) {
        // Restaurar transient
        set_transient('wpmps_subscribers_cache', $backup['data'], $backup['ttl']);
        
        if (function_exists('wpmps_log_admin')){
          wpmps_log_admin('cache_hit_backup', [
            'count' => count($backup['data']),
            'age_minutes' => round($age / 60, 1)
          ]);
        }
        return $backup['data'];
      }
    }
    
    return false;
  }

  // Método para limpiar caché
  public static function clear_cache() {
    delete_transient('wpmps_subscribers_cache');
    delete_option('wpmps_subscribers_backup');
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('subscribers_cache_cleared', []);
    }
  }

  // Método para actualizar datos en background (para usar con AJAX o cron)
  public static function refresh_subscribers_background($batch_size = 5) {
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (!$token) return false;
    
    // Obtener usuarios que necesitan actualización
    $args = [
      'meta_query' => [
        'relation' => 'AND',
        [ 'key' => '_mp_preapproval_id', 'compare' => 'EXISTS' ],
        [
          'relation' => 'OR',
          [ 'key' => '_mp_updated_at', 'compare' => 'NOT EXISTS' ],
          [ 'key' => '_mp_updated_at', 'value' => date('Y-m-d H:i:s', time() - 3600), 'compare' => '<' ]
        ]
      ],
      'fields' => ['ID'],
      'number' => $batch_size,
    ];
    
    $users = get_users($args);
    $client = new WPMPS_MP_Client($token);
    $updated = 0;
    
    foreach ($users as $user) {
      $preapproval_id = get_user_meta($user->ID, '_mp_preapproval_id', true);
      if ($preapproval_id) {
        try {
          $resp = $client->get_preapproval($preapproval_id);
          if (($resp['http'] ?? 0) === 200 && !empty($resp['body'])) {
            $item = $resp['body'];
            $auto = is_array($item['auto_recurring'] ?? null) ? $item['auto_recurring'] : [];
            $mp_status = sanitize_text_field($item['status'] ?? '');
            
            // Actualizar caché del usuario
            update_user_meta($user->ID, '_mp_updated_at', current_time('mysql'));
            update_user_meta($user->ID, '_mp_status_cache', $mp_status);
            update_user_meta($user->ID, '_mp_amount_cache', isset($auto['transaction_amount']) ? floatval($auto['transaction_amount']) : '');
            update_user_meta($user->ID, '_mp_currency_cache', sanitize_text_field($auto['currency_id'] ?? ''));
            
            $updated++;
          }
        } catch (\Throwable $e) {
          // Log error but continue
          if (function_exists('wpmps_log_error')){
            wpmps_log_error('subscribers', 'background_refresh_error', $e->getMessage());
          }
        }
      }
    }
    
    // Limpiar caché principal para forzar regeneración
    self::clear_cache();
    
    return $updated;
  }

  // Método para pre-calentar el caché (para usar en cron)
  public static function warm_cache() {
    // Obtener datos frescos con límite conservador de llamadas MP
    $data = self::get_subscribers_fast(false, 8);
    
    // Guardar en caché persistente
    self::set_persistent_cache($data, 900);
    
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('cache_warmed', [
        'count' => count($data),
        'timestamp' => current_time('mysql')
      ]);
    }
    
    return count($data);
  }

  // Método para mantener el caché (verificar y regenerar si es necesario)
  public static function maintain_cache() {
    $backup = get_option('wpmps_subscribers_backup');
    
    if ($backup && isset($backup['timestamp'])) {
      $age = time() - $backup['timestamp'];
      
      // Si el backup es muy viejo (más de 30 minutos), regenerar
      if ($age > 1800) {
        return self::warm_cache();
      }
    } else {
      // No hay backup, crear uno
      return self::warm_cache();
    }
    
    return 0; // No se necesitó mantenimiento
  }

  public static function cleanup_old_token_data(){
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (!$token) {
      return ['success' => false, 'message' => 'No hay token de acceso configurado'];
    }

    // Get all users with subscription metadata
    $args = [
      'meta_query' => [
        'relation' => 'OR',
        [ 'key' => '_mp_preapproval_id', 'compare' => 'EXISTS' ],
        [ 'key' => '_suscripcion_activa', 'compare' => 'EXISTS' ],
      ],
      'fields' => ['ID','user_email'],
      'number' => 500,
    ];
    $users = get_users($args);

    $client = new WPMPS_MP_Client($token);
    $cleaned_count = 0;
    $errors = [];

    foreach ($users as $u) {
      $preapproval_id = get_user_meta($u->ID, '_mp_preapproval_id', true);
      
      if (!$preapproval_id) continue;

      try {
        $resp = $client->get_preapproval($preapproval_id);
        
        // If we can't access this preapproval with current token, clean it
        if (($resp['http'] ?? 0) !== 200) {
          // Remove old token metadata
          delete_user_meta($u->ID, '_mp_preapproval_id');
          delete_user_meta($u->ID, '_mp_plan_id');
          delete_user_meta($u->ID, '_mp_plan_name');
          delete_user_meta($u->ID, '_mp_updated_at');
          delete_user_meta($u->ID, '_suscripcion_activa');
          
          $cleaned_count++;
          
          if (function_exists('wpmps_log_admin')){
            wpmps_log_admin('cleanup_old_token_data', [
              'user_id' => $u->ID,
              'email' => $u->user_email,
              'preapproval_id' => $preapproval_id,
              'http_code' => $resp['http'] ?? 0
            ]);
          }
        }
      } catch (\Throwable $e) {
        $errors[] = "Error procesando usuario {$u->user_email}: " . $e->getMessage();
      }
    }

    return [
      'success' => true,
      'cleaned_count' => $cleaned_count,
      'errors' => $errors,
      'message' => sprintf('Se limpiaron %d usuarios con datos de tokens anteriores', $cleaned_count)
    ];
  }

  public static function get_filtered_subscribers($filters = []){
    $all_subs = self::get_subscribers();
    
    // Log filtering start
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_filtered_subscribers_start', [
        'original_count' => count($all_subs),
        'filters' => $filters
      ]);
    }
    
    // Apply filters
    if (!empty($filters['status'])) {
      $status = sanitize_text_field($filters['status']);
      $all_subs = array_filter($all_subs, function($sub) use ($status) {
        return $sub['status'] === $status;
      });
    }
    
    if (!empty($filters['sync_status'])) {
      $sync_status = sanitize_text_field($filters['sync_status']);
      $all_subs = array_filter($all_subs, function($sub) use ($sync_status) {
        return $sub['sync_status'] === $sync_status;
      });
    }
    
    if (!empty($filters['email'])) {
      $email = sanitize_text_field($filters['email']);
      $all_subs = array_filter($all_subs, function($sub) use ($email) {
        return stripos($sub['email'], $email) !== false;
      });
    }
    
    // Filter by priority (show important cases first)
    if (!empty($filters['priority'])) {
      $priority = sanitize_text_field($filters['priority']);
      if ($priority === 'actionable') {
        // Show only cases that need action
        $all_subs = array_filter($all_subs, function($sub) {
          return $sub['sync_status'] === 'needs_role_change';
        });
      } elseif ($priority === 'ok') {
        // Show only cases that are OK
        $all_subs = array_filter($all_subs, function($sub) {
          return $sub['sync_status'] === 'ok';
        });
      } elseif ($priority === 'different_token') {
        // Show cases from different/previous tokens
        $all_subs = array_filter($all_subs, function($sub) {
          return $sub['sync_status'] === 'different_token';
        });
      } elseif ($priority === 'irrelevant') {
        // Show archived cases (for "show more")
        $all_subs = array_filter($all_subs, function($sub) {
          return $sub['sync_status'] === 'irrelevant';
        });
      }
    }
    
    // Log filtering result
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_filtered_subscribers_result', [
        'filtered_count' => count($all_subs)
      ]);
    }
    
    return array_values($all_subs);
  }
}
