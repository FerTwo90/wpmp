<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Subscribers {
  public static function get_subscribers(){
    $rows = [];
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    
    // Log start
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_subscribers_start', [
        'has_token' => !empty($token),
        'token_length' => $token ? strlen($token) : 0
      ]);
    }
    
    // Start from WordPress users with subscription metadata
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
    
    // Log WP users found
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_subscribers_wp_users_found', [
        'count' => count($users),
        'first_email' => !empty($users) ? $users[0]->user_email : 'No users'
      ]);
    }
    
    // Initialize MP client if we have token
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
    
    foreach ($users as $u){
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
        'token_status'   => 'unknown', // New field to identify token ownership
      ];
      
      // Try to get fresh data from MP if we have client and preapproval_id
      if ($client && $preapproval_id) {
        try {
          $resp = $client->get_preapproval($preapproval_id);
          
          if (function_exists('wpmps_log_admin')){
            wpmps_log_admin('get_subscribers_mp_lookup', [
              'user_id' => $u->ID,
              'preapproval_id' => $preapproval_id,
              'http_code' => $resp['http'] ?? 0
            ]);
          }
          
          if (($resp['http'] ?? 0) === 200 && !empty($resp['body'])) {
            // This preapproval belongs to current token
            $row['token_status'] = 'current_token';
            
            $item = $resp['body'];
            $auto = is_array($item['auto_recurring'] ?? null) ? $item['auto_recurring'] : [];
            $mp_status = sanitize_text_field($item['status'] ?? '');
            
            // Update row with MP data
            $row['status'] = $mp_status;
            $row['reason'] = sanitize_text_field($item['reason'] ?? '');
            $row['amount'] = isset($auto['transaction_amount']) ? floatval($auto['transaction_amount']) : '';
            $row['currency'] = sanitize_text_field($auto['currency_id'] ?? '');
            $row['frequency'] = isset($auto['frequency']) ? intval($auto['frequency']) : '';
            $row['frequency_type'] = sanitize_text_field($auto['frequency_type'] ?? '');
            $row['date_created'] = sanitize_text_field($item['date_created'] ?? '');
            $row['updated_at'] = sanitize_text_field($item['last_modified'] ?? ($item['date_created'] ?? $updated_at));
            
            // Get plan name from MP if not stored locally
            if (!$row['plan_name'] && !empty($item['preapproval_plan_id'])) {
              // Try to get plan details
              try {
                $plan_resp = $client->get_preapproval_plan($item['preapproval_plan_id']);
                if (($plan_resp['http'] ?? 0) === 200 && !empty($plan_resp['body']['reason'])) {
                  $row['plan_name'] = sanitize_text_field($plan_resp['body']['reason']);
                  // Store it for future use
                  update_user_meta($u->ID, '_mp_plan_name', $row['plan_name']);
                }
              } catch (\Throwable $e) {
                // Plan lookup failed, continue without plan name
              }
            }
            
            // Determine sync status based on business rules (only for current token)
            $mp_active = ($mp_status === 'authorized');
            
            if ($mp_active && $is_subscriber) {
              // MP active + subscriber role = everything is good
              $row['sync_status'] = 'ok';
            } elseif (!$mp_active && $is_subscriber) {
              // MP inactive but user still subscriber = needs action
              $row['sync_status'] = 'needs_role_change';
            } else {
              // All other cases are irrelevant (but still valid for current token)
              $row['sync_status'] = 'irrelevant';
            }
          } else {
            // This preapproval doesn't belong to current token
            $row['token_status'] = 'different_token';
            $row['sync_status'] = 'different_token';
            $row['status'] = 'unknown'; // Can't determine status without access
            
            if (function_exists('wpmps_log_admin')){
              wpmps_log_admin('get_subscribers_different_token', [
                'user_id' => $u->ID,
                'email' => $u->user_email,
                'preapproval_id' => $preapproval_id,
                'http_code' => $resp['http'] ?? 0
              ]);
            }
          }
        } catch (\Throwable $e) {
          if (function_exists('wpmps_log_error')){
            wpmps_log_error('subscribers', 'mp_lookup_error', $e->getMessage());
          }
          // Likely from different token or network error
          $row['token_status'] = 'error';
          $row['sync_status'] = 'error';
        }
      } else {
        // No MP client or preapproval_id
        if ($preapproval_id && !$client) {
          $row['token_status'] = 'no_client';
          $row['sync_status'] = 'no_token';
        } elseif (!$preapproval_id) {
          $row['token_status'] = 'no_preapproval';
          $row['sync_status'] = 'no_preapproval';
        }
      }
      
      $rows[] = $row;
    }
    
    // Log final result
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('get_subscribers_final_result', [
        'count' => count($rows),
        'source' => 'wp_based_with_mp_sync'
      ]);
    }
    
    return $rows;
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
