<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Payments_Subscriptions {
  const TABLE_SLUG    = 'wpmps_mapping';
  const OPTION_DB_VER = 'wpmps_mapping_db_version';
  const DB_VERSION    = '1.2.0';

  /**
   * Inicializa hooks para asegurar la tabla.
   */
  public static function init(){
    add_action('init', [__CLASS__, 'maybe_install_table']);
  }

  /**
   * Retorna el nombre completo de la tabla.
   */
  public static function table_name(){
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SLUG;
  }

  /**
   * Verifica y crea la tabla si corresponde.
   */
  public static function maybe_install_table(){
    global $wpdb;
    $table   = self::table_name();
    $exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $version = get_option(self::OPTION_DB_VER);

    if ($exists !== $table || $version !== self::DB_VERSION) {
      self::install_table();
    }
  }

  /**
   * Define el esquema consolidado de pagos/suscripciones.
   */
  public static function install_table(){
    global $wpdb;
    $table   = self::table_name();
    $collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      preapproval_id varchar(64) NOT NULL,
      plan_id varchar(64) DEFAULT NULL,
      plan_name varchar(255) DEFAULT NULL,
      amount decimal(10,2) DEFAULT NULL,
      payer_first_name varchar(100) DEFAULT NULL,
      payer_last_name varchar(100) DEFAULT NULL,
      payer_email varchar(191) DEFAULT NULL,
      payer_identification varchar(64) DEFAULT NULL,
      user_id bigint(20) unsigned DEFAULT NULL,
      status varchar(32) DEFAULT NULL,
      created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      payer_id varchar(32) DEFAULT NULL,
      payment_ids TEXT DEFAULT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY preapproval_id (preapproval_id),
      KEY plan_id (plan_id),
      KEY status (status),
      KEY payer_id (payer_id),
      KEY payer_email (payer_email)
    ) {$collate};";

    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option(self::OPTION_DB_VER, self::DB_VERSION, false);
  }

  /**
   * Devuelve todas las suscripciones con sus pagos asociados.
   */
  public static function get_subscriptions($filters = []) {
    global $wpdb;
    self::maybe_install_table();

    $table  = self::table_name();
    $limit  = isset($filters['limit']) ? max(1, intval($filters['limit'])) : 25;
    $offset = isset($filters['offset']) ? max(0, intval($filters['offset'])) : 0;

    $where   = [];
    $params  = [];

    if (!empty($filters['status'])) {
      $where[] = 'status = %s';
      $params[] = sanitize_text_field($filters['status']);
    }

    if (!empty($filters['plan_id'])) {
      $where[] = 'plan_id = %s';
      $params[] = sanitize_text_field($filters['plan_id']);
    }

    if (!empty($filters['preapproval_id'])) {
      $where[] = 'preapproval_id = %s';
      $params[] = sanitize_text_field($filters['preapproval_id']);
    }

    if (!empty($filters['payer_identification'])) {
      $where[] = 'payer_identification LIKE %s';
      $params[] = '%' . sanitize_text_field($filters['payer_identification']) . '%';
    }

    if (!empty($filters['payment_id'])) {
      $where[] = 'payment_ids LIKE %s';
      $params[] = '%' . sanitize_text_field($filters['payment_id']) . '%';
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    // Ordenamiento
    $allowed_orderby = ['preapproval_id', 'plan_name', 'amount', 'payer_email', 'status', 'created_at', 'payer_id'];
    $orderby = isset($filters['orderby']) && in_array($filters['orderby'], $allowed_orderby) ? $filters['orderby'] : 'created_at';
    $order = isset($filters['order']) && in_array($filters['order'], ['ASC', 'DESC']) ? $filters['order'] : 'DESC';
    $order_sql = "ORDER BY s.{$orderby} {$order}";
    $query_sql = "SELECT s.*, 
                         u.user_email as wp_user_email,
                         u.display_name as wp_display_name,
                         CONCAT(um_first.meta_value, ' ', um_last.meta_value) as wp_full_name
                  FROM {$table} s
                  LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                  LEFT JOIN {$wpdb->usermeta} um_first ON (u.ID = um_first.user_id AND um_first.meta_key = 'first_name')
                  LEFT JOIN {$wpdb->usermeta} um_last ON (u.ID = um_last.user_id AND um_last.meta_key = 'last_name')
                  {$where_sql} {$order_sql} LIMIT %d OFFSET %d";
    
    // Reemplazar referencias a columnas en WHERE
    $query_sql = str_replace(['status =', 'plan_id =', 'preapproval_id =', 'payer_identification LIKE', 'payment_ids LIKE'], 
                            ['s.status =', 's.plan_id =', 's.preapproval_id =', 's.payer_identification LIKE', 's.payment_ids LIKE'], 
                            $query_sql);
    
    $query_params = array_merge($params, [$limit, $offset]);
    $prepared_query = $wpdb->prepare($query_sql, $query_params);
    $rows = $wpdb->get_results($prepared_query, ARRAY_A);

    $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
    $prepared_count = !empty($params) ? $wpdb->prepare($count_sql, $params) : $count_sql;
    $total = intval($wpdb->get_var($prepared_count));

    return [
      'success' => true,
      'subscriptions' => $rows ?: [],
      'total'   => $total,
      'limit'   => $limit,
      'offset'  => $offset,
    ];
  }



  /**
   * Sincroniza suscripciones de forma inteligente - solo las faltantes
   */
  public static function smart_sync_subscriptions($limit = 100) {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    if (!class_exists('WPMPS_Subscribers')) {
      return [
        'seeded'  => false,
        'message' => __('No se encontró la clase de suscriptores.', 'wp-mp-subscriptions')
      ];
    }

    // Obtener IDs de suscripciones que ya tenemos
    $existing_ids = $wpdb->get_col("SELECT preapproval_id FROM {$table} WHERE preapproval_id <> ''");
    
    // Hacer múltiples requests para obtener TODAS las suscripciones
    $all_subscriptions = [];
    $offset = 0;
    $batch_size = 50;
    
    do {
      $latest = WPMPS_Subscribers::get_latest_subscriptions($batch_size, $offset);
      if (empty($latest['success']) || empty($latest['subscriptions'])) {
        break;
      }
      
      $all_subscriptions = array_merge($all_subscriptions, $latest['subscriptions']);
      $offset += $batch_size;
      
      // Continuar hasta que no haya más suscripciones o lleguemos al límite
    } while (count($latest['subscriptions']) == $batch_size && count($all_subscriptions) < $limit);

    if (empty($all_subscriptions)) {
      return [
        'seeded'  => false,
        'message' => __('No se pudieron obtener suscripciones desde Mercado Pago.', 'wp-mp-subscriptions')
      ];
    }

    // Filtrar solo las que no tenemos
    $missing_subs = [];
    foreach ($all_subscriptions as $sub) {
      $sub_id = sanitize_text_field($sub['id'] ?? ($sub['preapproval_id'] ?? ''));
      if ($sub_id && !in_array($sub_id, $existing_ids)) {
        $missing_subs[] = $sub;
      }
    }

    if (empty($missing_subs)) {
      return [
        'seeded'  => false,
        'message' => __('Todas las suscripciones están actualizadas.', 'wp-mp-subscriptions')
      ];
    }

    // Obtener planes para mapear nombres
    $plans_map = [];
    if (class_exists('WPMPS_Sync')) {
      $plans = WPMPS_Sync::get_plans();
      foreach ($plans as $plan) {
        $plans_map[$plan['id']] = $plan['name'];
      }
    }

    $inserted = 0;
    foreach ($missing_subs as $sub) {
      $created_at = self::normalize_datetime($sub['date_created'] ?? ($sub['created_at'] ?? ''));

      // Extraer payer_id correctamente
      $payer_id = '';
      if (isset($sub['payer']['id'])) {
        $payer_id = sanitize_text_field((string)$sub['payer']['id']);
      } elseif (isset($sub['payer_id'])) {
        $payer_id = sanitize_text_field((string)$sub['payer_id']);
      }

      $plan_id = sanitize_text_field($sub['preapproval_plan_id'] ?? ($sub['plan_id'] ?? ''));
      $plan_name = isset($plans_map[$plan_id]) ? $plans_map[$plan_id] : '';

      $data = [
        'preapproval_id'=> sanitize_text_field($sub['id'] ?? ($sub['preapproval_id'] ?? '')),
        'plan_id'       => $plan_id,
        'plan_name'     => $plan_name,
        'amount'        => isset($sub['auto_recurring']['transaction_amount']) ? floatval($sub['auto_recurring']['transaction_amount']) : (isset($sub['amount']) ? floatval($sub['amount']) : null),
        'payer_first_name' => sanitize_text_field($sub['payer']['first_name'] ?? ($sub['payer_first_name'] ?? '')),
        'payer_last_name'  => sanitize_text_field($sub['payer']['last_name'] ?? ($sub['payer_last_name'] ?? '')),
        'payer_email'      => sanitize_email($sub['payer']['email'] ?? ($sub['payer_email'] ?? '')),
        'payer_identification' => sanitize_text_field($sub['payer']['identification']['number'] ?? ''),
        'user_id'       => null,
        'status'        => sanitize_text_field($sub['status'] ?? ''),
        'created_at'    => $created_at,
        'payer_id'      => $payer_id,
        'payment_ids'   => '', // Se llenará con los pagos
      ];

      $result = $wpdb->insert($table, $data);
      if ($result !== false) {
        $inserted++;
      }
    }

    return [
      'seeded'  => $inserted > 0,
      'inserted'=> $inserted,
      'message' => $inserted > 0
        ? sprintf(__('Se agregaron %d suscripciones nuevas desde Mercado Pago.', 'wp-mp-subscriptions'), $inserted)
        : __('No se encontraron suscripciones nuevas.', 'wp-mp-subscriptions')
    ];
  }

  /**
   * Si la tabla no tiene suscripciones, obtiene las últimas desde MP y las inserta.
   */
  public static function bootstrap_subscriptions_if_empty($limit = 25){
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    $existing = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));

    if ($existing > 0) {
      return [
        'seeded'  => false,
        'message' => ''
      ];
    }

    if (!class_exists('WPMPS_Subscribers')) {
      return [
        'seeded'  => false,
        'message' => __('No se encontró la clase de suscriptores para poblar la tabla.', 'wp-mp-subscriptions')
      ];
    }

    $latest = WPMPS_Subscribers::get_latest_subscriptions($limit);
    if (empty($latest['success']) || empty($latest['subscriptions'])) {
      return [
        'seeded'  => false,
        'message' => !empty($latest['message'])
          ? $latest['message']
          : __('Mercado Pago no devolvió suscripciones para inicializar la tabla.', 'wp-mp-subscriptions')
      ];
    }

    // Obtener planes para mapear nombres
    $plans_map = [];
    if (class_exists('WPMPS_Sync')) {
      $plans = WPMPS_Sync::get_plans();
      foreach ($plans as $plan) {
        $plans_map[$plan['id']] = $plan['name'];
      }
    }

    $inserted = 0;
    foreach ($latest['subscriptions'] as $sub) {
      $created_at = self::normalize_datetime($sub['date_created'] ?? ($sub['created_at'] ?? ''));

      // Extraer payer_id correctamente desde la estructura de la suscripción
      $payer_id = '';
      if (isset($sub['payer']['id'])) {
        $payer_id = sanitize_text_field((string)$sub['payer']['id']);
      } elseif (isset($sub['payer_id'])) {
        $payer_id = sanitize_text_field((string)$sub['payer_id']);
      }

      $plan_id = sanitize_text_field($sub['preapproval_plan_id'] ?? ($sub['plan_id'] ?? ''));
      $plan_name = isset($plans_map[$plan_id]) ? $plans_map[$plan_id] : '';

      $data = [
        'preapproval_id'=> sanitize_text_field($sub['id'] ?? ($sub['preapproval_id'] ?? '')),
        'plan_id'       => $plan_id,
        'plan_name'     => $plan_name,
        'amount'        => isset($sub['auto_recurring']['transaction_amount']) ? floatval($sub['auto_recurring']['transaction_amount']) : (isset($sub['amount']) ? floatval($sub['amount']) : null),
        'payer_first_name' => sanitize_text_field($sub['payer']['first_name'] ?? ($sub['payer_first_name'] ?? '')),
        'payer_last_name'  => sanitize_text_field($sub['payer']['last_name'] ?? ($sub['payer_last_name'] ?? '')),
        'payer_email'      => sanitize_email($sub['payer']['email'] ?? ($sub['payer_email'] ?? '')),
        'payer_identification' => sanitize_text_field($sub['payer']['identification']['number'] ?? ''),
        'user_id'       => null,
        'status'        => sanitize_text_field($sub['status'] ?? ''),
        'created_at'    => $created_at,
        'payer_id'      => $payer_id,
        'payment_ids'   => '', // Se llenará con los pagos
      ];

      $result = $wpdb->insert($table, $data);
      if ($result !== false) {
        $inserted++;
      }
    }

    return [
      'seeded'  => $inserted > 0,
      'inserted'=> $inserted,
      'message' => $inserted > 0
        ? sprintf(__('Se cargaron %d suscripciones recientes desde Mercado Pago.', 'wp-mp-subscriptions'), $inserted)
        : __('No se pudieron insertar suscripciones en la tabla.', 'wp-mp-subscriptions')
    ];
  }



  /**
   * PASO 2 y 3: Completar datos de pagos siguiendo la lógica correcta
   */
  public static function complete_payment_data() {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    // Obtener todas las suscripciones
    $all_subs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A);
    
    if (empty($all_subs)) {
      return [
        'success' => false,
        'message' => __('No hay suscripciones para procesar.', 'wp-mp-subscriptions')
      ];
    }

    // PASO 2: ¿Tienen datos que vienen de los pagos las suscripciones?
    $subs_with_data = [];
    $subs_without_data = [];
    
    foreach ($all_subs as $sub) {
      $has_payment_data = !empty($sub['payment_ids']) && 
                         !empty($sub['payer_email']) && 
                         !empty($sub['payer_identification']);
      
      if ($has_payment_data) {
        $subs_with_data[$sub['payer_id']][] = $sub;
      } else {
        $subs_without_data[] = $sub;
      }
    }

    $updated = 0;
    $api_requests = 0;
    
    // PASO 3: ¿Puedo sacar esos datos de otra fila?
    foreach ($subs_without_data as $sub) {
      $payer_id = $sub['payer_id'];
      
      // Buscar si hay otra suscripción del mismo payer_id con datos completos
      if (isset($subs_with_data[$payer_id])) {
        $complete_sub = $subs_with_data[$payer_id][0]; // Tomar la primera con datos
        
        $update_data = [];
        if (empty($sub['payment_ids'])) {
          $update_data['payment_ids'] = $complete_sub['payment_ids'];
        }
        if (empty($sub['payer_email'])) {
          $update_data['payer_email'] = $complete_sub['payer_email'];
        }
        if (empty($sub['payer_identification'])) {
          $update_data['payer_identification'] = $complete_sub['payer_identification'];
        }
        
        if (!empty($update_data)) {
          $wpdb->update($table, $update_data, ['id' => $sub['id']]);
          $updated++;
        }
        
        continue; // No necesita request a la API
      }
      
      // Si no hay datos de otra fila, hacer request a la API
      if (!empty($payer_id)) {
        $payment_data = self::fetch_payment_data_for_payer($payer_id);
        
        if ($payment_data['success']) {
          $api_requests++;
          
          $update_data = [];
          if (empty($sub['payment_ids']) && !empty($payment_data['payment_ids'])) {
            $update_data['payment_ids'] = $payment_data['payment_ids'];
          }
          if (empty($sub['payer_email']) && !empty($payment_data['payer_email'])) {
            $update_data['payer_email'] = $payment_data['payer_email'];
          }
          if (empty($sub['payer_identification']) && !empty($payment_data['payer_identification'])) {
            $update_data['payer_identification'] = $payment_data['payer_identification'];
          }
          
          if (!empty($update_data)) {
            $wpdb->update($table, $update_data, ['id' => $sub['id']]);
            $updated++;
            
            // Actualizar también otras suscripciones del mismo payer_id
            $other_subs = $wpdb->get_results($wpdb->prepare(
              "SELECT id FROM {$table} WHERE payer_id = %s AND id != %s AND (payment_ids = '' OR payer_email = '' OR payer_identification = '')",
              $payer_id, $sub['id']
            ), ARRAY_A);
            
            foreach ($other_subs as $other_sub) {
              $wpdb->update($table, $update_data, ['id' => $other_sub['id']]);
              $updated++;
            }
          }
        }
      }
    }

    return [
      'success' => true,
      'updated' => $updated,
      'api_requests' => $api_requests,
      'message' => $updated > 0
        ? sprintf(__('Se completaron datos de %1$d suscripciones con %2$d requests a la API.', 'wp-mp-subscriptions'), $updated, $api_requests)
        : __('Todas las suscripciones ya tienen datos completos.', 'wp-mp-subscriptions')
    ];
  }

  /**
   * Obtener datos de pagos para un payer_id específico
   */
  private static function fetch_payment_data_for_payer($payer_id) {
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (empty($token)) {
      return ['success' => false];
    }

    try {
      $client = new WPMPS_MP_Client($token);
      $response = $client->search_payments([
        'sort'     => 'date_created',
        'criteria' => 'desc',
        'limit'    => 100,
        'payer.id' => $payer_id
      ]);

      $http_code = $response['http'] ?? 0;
      $body = $response['body'] ?? [];
      $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : [];

      if ($http_code !== 200 || empty($results)) {
        return ['success' => false];
      }

      $first_payment = $results[0];
      $payment_ids = [];
      foreach ($results as $payment) {
        $payment_id = sanitize_text_field((string)($payment['id'] ?? ''));
        if ($payment_id) {
          $payment_ids[] = $payment_id;
        }
      }

      $payer_email = sanitize_email($first_payment['payer']['email'] ?? '');
      $payer_identification = '';
      if (!empty($first_payment['payer']['identification']['number'])) {
        $payer_identification = sanitize_text_field(
          trim(($first_payment['payer']['identification']['type'] ?? '').' '.$first_payment['payer']['identification']['number'])
        );
      }

      return [
        'success' => true,
        'payment_ids' => implode(',', $payment_ids),
        'payer_email' => $payer_email,
        'payer_identification' => $payer_identification
      ];

    } catch (\Throwable $e) {
      return ['success' => false];
    }
  }

  /**
   * Sincroniza pagos para las suscripciones existentes de forma inteligente
   */
  public static function force_sync_all_payments($limit = 100) {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    $subscriptions = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$table} WHERE payer_id <> '' LIMIT %d", max(1, intval($limit))),
      ARRAY_A
    );

    if (empty($subscriptions)) {
      return [
        'seeded'  => false,
        'message' => __('No hay suscripciones con payer_id para sincronizar pagos.', 'wp-mp-subscriptions')
      ];
    }

    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (empty($token)) {
      return [
        'seeded'  => false,
        'message' => __('No se encontró Access Token de Mercado Pago para consultar pagos.', 'wp-mp-subscriptions')
      ];
    }

    try {
      $client = new WPMPS_MP_Client($token);
    } catch (\Throwable $e) {
      if (function_exists('wpmps_log_error')){
        wpmps_log_error('payments', 'client_init_error', $e->getMessage());
      }
      return [
        'seeded'  => false,
        'message' => __('No se pudo inicializar el cliente de Mercado Pago para pagos.', 'wp-mp-subscriptions')
      ];
    }

    // Agrupar suscripciones por payer_id para evitar requests duplicados
    $payer_groups = [];
    $subs_needing_data = [];
    
    foreach ($subscriptions as $subscription) {
      $payer_id = $subscription['payer_id'];
      
      if (!isset($payer_groups[$payer_id])) {
        $payer_groups[$payer_id] = [];
      }
      $payer_groups[$payer_id][] = $subscription;
      
      // Marcar suscripciones que necesitan datos (solo payment_ids es crítico)
      $needs_data = empty($subscription['payment_ids']);
      
      if ($needs_data) {
        $subs_needing_data[] = $subscription['id'];
      }
    }

    $updated = 0;
    $api_requests = 0;
    $payer_data_cache = [];

    foreach ($payer_groups as $payer_id => $group_subs) {
      // Verificar si alguna suscripción de este grupo necesita datos
      $group_needs_data = false;
      $complete_sub = null;
      
      foreach ($group_subs as $sub) {
        if (in_array($sub['id'], $subs_needing_data)) {
          $group_needs_data = true;
        } else {
          // Esta suscripción tiene datos completos, la usamos como referencia
          $complete_sub = $sub;
        }
      }
      
      // Si hay una suscripción completa, reutilizar sus datos
      if ($complete_sub && $group_needs_data) {
        foreach ($group_subs as $sub) {
          if (in_array($sub['id'], $subs_needing_data)) {
            $update_data = [];
            
            if (empty($sub['payment_ids']) && !empty($complete_sub['payment_ids'])) {
              $update_data['payment_ids'] = $complete_sub['payment_ids'];
            }
            if (empty($sub['payer_email']) && !empty($complete_sub['payer_email'])) {
              $update_data['payer_email'] = $complete_sub['payer_email'];
            }
            if (empty($sub['payer_identification']) && !empty($complete_sub['payer_identification'])) {
              $update_data['payer_identification'] = $complete_sub['payer_identification'];
            }
            
            if (!empty($update_data)) {
              $wpdb->update($table, $update_data, ['id' => $sub['id']]);
              $updated++;
            }
          }
        }
        continue; // No hacer request a la API
      }
      
      // Si no hay datos completos, hacer request a la API
      if ($group_needs_data) {
        $api_requests++;
        
        $response = $client->search_payments([
          'sort'     => 'date_created',
          'criteria' => 'desc',
          'limit'    => 100,
          'payer.id' => $payer_id
        ]);

        $http_code = $response['http'] ?? 0;
        $body = $response['body'] ?? [];
        $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : [];

        // Log para debug
        if (function_exists('wpmps_log_error')) {
          wpmps_log_error('payments_debug', 'api_request', "Payer ID: {$payer_id}, HTTP: {$http_code}, Results: " . count($results), [
            'payer_id' => $payer_id,
            'http_code' => $http_code,
            'results_count' => count($results),
            'body_preview' => is_array($body) ? array_keys($body) : (is_string($body) ? substr($body, 0, 200) : 'unknown')
          ]);
        }

        if ($http_code !== 200 || empty($results)) {
          continue;
        }

        // Extraer datos del primer pago
        $first_payment = $results[0];
        $payer_email = sanitize_email($first_payment['payer']['email'] ?? '');
        $payer_identification = '';
        if (!empty($first_payment['payer']['identification']['number'])) {
          $payer_identification = sanitize_text_field(
            trim(($first_payment['payer']['identification']['type'] ?? '').' '.$first_payment['payer']['identification']['number'])
          );
        }
        
        $payment_ids = [];
        foreach ($results as $payment) {
          $payment_id = sanitize_text_field((string)($payment['id'] ?? ''));
          if ($payment_id) {
            $payment_ids[] = $payment_id;
          }
        }
        
        // Actualizar todas las suscripciones de este payer_id
        foreach ($group_subs as $sub) {
          $update_data = [];
          
          // Siempre actualizar payment_ids si los encontramos
          if (!empty($payment_ids)) {
            $update_data['payment_ids'] = implode(',', $payment_ids);
          }
          
          // Actualizar email y documento si están vacíos o si es el payer_id problemático
          if ((empty($sub['payer_email']) || $payer_id == '39757231') && !empty($payer_email)) {
            $update_data['payer_email'] = $payer_email;
          }
          if ((empty($sub['payer_identification']) || $payer_id == '39757231') && !empty($payer_identification)) {
            $update_data['payer_identification'] = $payer_identification;
          }
          
          if (!empty($update_data)) {
            $result = $wpdb->update($table, $update_data, ['id' => $sub['id']]);
            if ($result !== false) {
              $updated++;
            }
            
            // Log específico para el payer problemático
            if ($payer_id == '39757231' && function_exists('wpmps_log_error')) {
              wpmps_log_error('payments_debug', 'update_result', "Updated sub ID {$sub['id']} for payer {$payer_id}", [
                'sub_id' => $sub['id'],
                'payer_id' => $payer_id,
                'update_data' => $update_data,
                'update_result' => $result
              ]);
            }
          }
        }
      }
    }

    return [
      'seeded'  => $updated > 0,
      'updated' => $updated,
      'api_requests' => $api_requests,
      'message' => $updated > 0
        ? sprintf(__('Se actualizaron %1$d suscripciones con %2$d requests a la API de Mercado Pago.', 'wp-mp-subscriptions'), $updated, $api_requests)
        : sprintf(__('Todas las suscripciones ya tienen datos completos. Se evitaron requests innecesarios.', 'wp-mp-subscriptions'))
    ];
  }

  /**
   * Obtiene estadísticas básicas de la tabla
   */
  public static function get_stats() {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    $stats = [
      'total_subscriptions' => 0,
      'total_payments' => 0,
      'matched_payments' => 0,
      'unmatched_payments' => 0,
      'unique_payers' => 0,
      'last_sync' => get_transient('wpmps_payments_last_sync')
    ];

    $stats['total_subscriptions'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s", 'subscription')
    ));

    $stats['total_payments'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s", 'payment')
    ));

    $stats['matched_payments'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND preapproval_id <> ''", 'payment')
    ));

    $stats['unmatched_payments'] = $stats['total_payments'] - $stats['matched_payments'];

    $stats['unique_payers'] = intval($wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(DISTINCT payer_id) FROM {$table} WHERE payer_id <> ''")
    ));

    return $stats;
  }

  /**
   * Mapea usuarios de WordPress con suscripciones basado en metadatos
   */
  public static function map_users_to_subscriptions() {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    // Obtener todas las suscripciones sin user_id mapeado
    $unmapped_subs = $wpdb->get_results(
      "SELECT id, preapproval_id, payer_email FROM {$table} WHERE user_id IS NULL OR user_id = 0",
      ARRAY_A
    );

    if (empty($unmapped_subs)) {
      return [
        'success' => true,
        'mapped' => 0,
        'message' => __('Todas las suscripciones ya están mapeadas con usuarios.', 'wp-mp-subscriptions')
      ];
    }

    $mapped = 0;
    foreach ($unmapped_subs as $sub) {
      $preapproval_id = $sub['preapproval_id'];
      $payer_email = $sub['payer_email'];
      
      $user_id = null;
      
      // Buscar por preapproval_id en metadatos de usuario
      if (!empty($preapproval_id)) {
        $user_id = $wpdb->get_var($wpdb->prepare(
          "SELECT user_id FROM {$wpdb->usermeta} 
           WHERE meta_key LIKE '%preapproval%' 
           AND meta_value = %s 
           LIMIT 1",
          $preapproval_id
        ));
      }
      
      // Si no se encontró por preapproval_id, buscar por email
      if (!$user_id && !empty($payer_email)) {
        $user_id = $wpdb->get_var($wpdb->prepare(
          "SELECT ID FROM {$wpdb->users} WHERE user_email = %s LIMIT 1",
          $payer_email
        ));
      }
      
      // Si encontramos un usuario, actualizar la suscripción
      if ($user_id) {
        $wpdb->update(
          $table,
          ['user_id' => intval($user_id)],
          ['id' => $sub['id']]
        );
        $mapped++;
      }
    }

    return [
      'success' => true,
      'mapped' => $mapped,
      'message' => $mapped > 0 
        ? sprintf(__('Se mapearon %d suscripciones con usuarios de WordPress.', 'wp-mp-subscriptions'), $mapped)
        : __('No se encontraron coincidencias entre suscripciones y usuarios.', 'wp-mp-subscriptions')
    ];
  }

  /**
   * Debug específico para un payer_id
   */
  public static function debug_payer($payer_id) {
    global $wpdb;
    self::maybe_install_table();
    $table = self::table_name();

    // Obtener suscripciones de este payer_id
    $subscriptions = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$table} WHERE payer_id = %s", $payer_id),
      ARRAY_A
    );

    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (empty($token)) {
      return ['error' => 'No token'];
    }

    try {
      $client = new WPMPS_MP_Client($token);
      $response = $client->search_payments([
        'sort'     => 'date_created',
        'criteria' => 'desc',
        'limit'    => 100,
        'payer.id' => $payer_id
      ]);

      return [
        'payer_id' => $payer_id,
        'subscriptions_in_db' => count($subscriptions),
        'subscriptions_data' => $subscriptions,
        'api_response' => [
          'http_code' => $response['http'] ?? 0,
          'results_count' => isset($response['body']['results']) ? count($response['body']['results']) : 0,
          'body_keys' => is_array($response['body']) ? array_keys($response['body']) : 'not_array',
          'first_payment' => isset($response['body']['results'][0]) ? $response['body']['results'][0] : null
        ]
      ];
    } catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Limpia el caché de sincronización
   */
  public static function clear_sync_cache() {
    delete_transient('wpmps_payments_last_sync');
    return true;
  }

  /**
   * Borra toda la tabla para reiniciar el proceso
   */
  public static function reset_table() {
    global $wpdb;
    $table = self::table_name();
    
    $result = $wpdb->query("TRUNCATE TABLE {$table}");
    
    if ($result !== false) {
      self::clear_sync_cache();
      return [
        'success' => true,
        'message' => __('Tabla reiniciada correctamente. Se volverán a cargar los datos en la próxima sincronización.', 'wp-mp-subscriptions')
      ];
    } else {
      return [
        'success' => false,
        'message' => __('Error al reiniciar la tabla.', 'wp-mp-subscriptions')
      ];
    }
  }

  private static function normalize_datetime($value){
    if (empty($value)) {
      return current_time('mysql');
    }
    $ts = strtotime($value);
    if (!$ts) {
      return current_time('mysql');
    }
    return gmdate('Y-m-d H:i:s', $ts);
  }
}
