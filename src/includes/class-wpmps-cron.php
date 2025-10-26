<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Cron {
  
  const HOOK_NAME = 'wpmps_check_subscriptions';
  const OPTION_LAST_RUN = 'wpmps_cron_last_run';
  const OPTION_ENABLED = 'wpmps_cron_enabled';
  
  public static function init() {
    // Register the cron hook
    add_action(self::HOOK_NAME, [__CLASS__, 'check_subscriptions']);
    
    // Schedule on activation if not already scheduled
    add_action('wp', [__CLASS__, 'maybe_schedule']);
    
    // Admin hooks
    add_action('admin_init', [__CLASS__, 'admin_actions']);
  }
  
  public static function maybe_schedule() {
    if (!wp_next_scheduled(self::HOOK_NAME)) {
      self::schedule();
    }
  }
  
  public static function schedule() {
    $enabled = get_option(self::OPTION_ENABLED, 'yes');
    if ($enabled !== 'yes') {
      return false;
    }
    
    // Schedule to run every 15 minutes
    wp_schedule_event(time(), 'wpmps_15min', self::HOOK_NAME);
    
    wpmps_log('CRON', wpmps_collect_context('cron_scheduled', [
      'hook' => self::HOOK_NAME,
      'next_run' => wp_next_scheduled(self::HOOK_NAME)
    ]));
    
    return true;
  }
  
  public static function unschedule() {
    wp_clear_scheduled_hook(self::HOOK_NAME);
    
    wpmps_log('CRON', wpmps_collect_context('cron_unscheduled', [
      'hook' => self::HOOK_NAME
    ]));
  }
  
  public static function check_subscriptions() {
    $start_time = microtime(true);
    
    wpmps_log('CRON', wpmps_collect_context('cron_started', [
      'timestamp' => current_time('mysql'),
      'memory_usage' => memory_get_usage(true)
    ]));
    
    // Update last run timestamp
    update_option(self::OPTION_LAST_RUN, current_time('mysql'));
    
    // Get configured subscription role
    $subscription_role = get_option('wpmps_role_on_authorized', '');
    if ($subscription_role === 1 || $subscription_role === '1') {
      $subscription_role = 'suscriptor_premium';
    }
    $subscription_role = trim((string) $subscription_role);
    
    // Debug role configuration
    wpmps_log('CRON', wpmps_collect_context('cron_role_config', [
      'raw_option' => get_option('wpmps_role_on_authorized', 'NOT_SET'),
      'processed_role' => $subscription_role,
      'role_exists' => !empty($subscription_role) ? (get_role($subscription_role) ? 'yes' : 'no') : 'empty',
    ]));
    
    // Use the same method as the subscribers page
    $subscribers = WPMPS_Subscribers::get_subscribers();
    
    if (empty($subscribers)) {
      wpmps_log('CRON', wpmps_collect_context('cron_no_subscribers', [
        'message' => 'No subscribers found'
      ]));
      return;
    }
    
    $processed = 0;
    $synced = 0;
    $role_changes = 0;
    $errors = 0;
    
    foreach ($subscribers as $sub) {
      // Skip if no user_id or if this is from a different token
      if (empty($sub['user_id']) || $sub['sync_status'] === 'different_token') {
        continue;
      }
      
      $user_id = $sub['user_id'];
      $user_email = $sub['email'];
      $mp_status = $sub['status'];
      $sync_status = $sub['sync_status'];
      $user_roles = $sub['user_roles'];
      
      $processed++;
      
      // Check if we should skip this user (rate limiting)
      $last_checked = get_user_meta($user_id, '_mp_last_checked', true);
      if ($last_checked) {
        $last_checked_time = strtotime($last_checked);
        $now = time();
        // Skip if checked less than 10 minutes ago
        if (($now - $last_checked_time) < 600) {
          continue;
        }
      }
      
      // Update last checked timestamp
      update_user_meta($user_id, '_mp_last_checked', current_time('mysql'));
      
      // Debug subscriber data
      wpmps_log('CRON', wpmps_collect_context('cron_subscriber_data', [
        'user_id' => $user_id,
        'user_email' => $user_email,
        'mp_status' => $mp_status,
        'sync_status' => $sync_status,
        'user_roles' => $user_roles,
        'subscription_role' => $subscription_role,
      ]));
      
      // Skip if no subscription role configured
      if (empty($subscription_role)) {
        continue;
      }
      
      $wp_user = new WP_User($user_id);
      $current_roles = $wp_user->roles;
      $has_subscription_role = in_array($subscription_role, $current_roles);
      $should_be_active = ($mp_status === 'authorized');
      
      // Determine if role sync is needed
      $needs_role_sync = false;
      $sync_reason = '';
      
      if ($sync_status === 'needs_role_change') {
        // MP inactive but user still has subscriber role - remove subscription role
        $needs_role_sync = true;
        $sync_reason = 'remove_role_mp_inactive';
      } elseif ($should_be_active && !$has_subscription_role) {
        // MP active but user doesn't have subscription role - add it
        $needs_role_sync = true;
        $sync_reason = 'add_role_mp_active';
      } elseif (!$should_be_active && $has_subscription_role) {
        // MP inactive but user has subscription role - remove it
        $needs_role_sync = true;
        $sync_reason = 'remove_role_mp_inactive';
      }
      
      if ($needs_role_sync) {
        $synced++;
        $roles_before = $current_roles;
        
        // Use the same function that handles role sync everywhere else
        if (function_exists('wpmps_sync_subscription_role')) {
          $role_sync_result = wpmps_sync_subscription_role($user_id, $mp_status);
          
          if ($role_sync_result) {
            $role_changes++;
            
            // Get roles after sync
            $wp_user_refreshed = new WP_User($user_id);
            $roles_after = $wp_user_refreshed->roles;
            
            wpmps_log_subscription('cron_role_synced', [
              'user_id' => $user_id,
              'user_email' => $user_email,
              'mp_status' => $mp_status,
              'sync_reason' => $sync_reason,
              'roles_before' => $roles_before,
              'roles_after' => $roles_after,
              'subscription_role' => $subscription_role,
              'should_be_active' => $should_be_active ? 'yes' : 'no',
            ]);
          }
        } else {
          wpmps_log_error('cron', 'sync_function_missing', 'wpmps_sync_subscription_role function not available', [
            'user_id' => $user_id
          ]);
        }
        
        // Update user metadata
        $active_flag = $should_be_active ? 'yes' : 'no';
        update_user_meta($user_id, '_suscripcion_activa', $active_flag);
        update_user_meta($user_id, '_mp_updated_at', current_time('mysql'));
        
        wpmps_log_subscription('cron_sync_completed', [
          'user_id' => $user_id,
          'user_email' => $user_email,
          'sync_reason' => $sync_reason,
          'mp_status' => $mp_status,
          'should_be_active' => $should_be_active ? 'yes' : 'no',
          'subscription_role' => $subscription_role,
          'roles_before' => $roles_before,
          'roles_after' => $roles_after,
          'mail_sent' => is_null($mail_sent) ? '' : ($mail_sent ? 'yes' : 'no'),
        ]);
      }
      
      // Rate limiting: small delay between users
      usleep(50000); // 0.05 seconds
    }
    
    $end_time = microtime(true);
    $duration = round($end_time - $start_time, 2);
    
    wpmps_log('CRON', wpmps_collect_context('cron_completed', [
      'duration_seconds' => $duration,
      'subscribers_processed' => $processed,
      'users_synced' => $synced,
      'role_changes' => $role_changes,
      'errors' => $errors,
      'memory_peak' => memory_get_peak_usage(true),
      'subscription_role' => $subscription_role
    ]));
  }
  
  public static function admin_actions() {
    // Handle manual cron trigger
    if (isset($_GET['action']) && $_GET['action'] === 'wpmps_run_cron' && 
        isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'wpmps_run_cron')) {
      
      if (current_user_can('manage_options')) {
        self::check_subscriptions();
        
        $redirect_url = remove_query_arg(['action', '_wpnonce']);
        $redirect_url = add_query_arg('cron_run', '1', $redirect_url);
        wp_redirect($redirect_url);
        exit;
      }
    }
    
    // Handle cron enable/disable
    if (isset($_POST['wpmps_cron_action']) && 
        isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'wpmps_cron_settings')) {
      
      if (current_user_can('manage_options')) {
        $action = sanitize_text_field($_POST['wpmps_cron_action']);
        
        if ($action === 'enable') {
          update_option(self::OPTION_ENABLED, 'yes');
          self::schedule();
          $message = __('Cron habilitado y programado', 'wp-mp-subscriptions');
        } elseif ($action === 'disable') {
          update_option(self::OPTION_ENABLED, 'no');
          self::unschedule();
          $message = __('Cron deshabilitado', 'wp-mp-subscriptions');
        }
        
        if (isset($message)) {
          add_settings_error('wpmps_cron', 'cron_updated', $message, 'updated');
        }
      }
    }
    

  }
  
  public static function get_status() {
    $enabled = get_option(self::OPTION_ENABLED, 'yes') === 'yes';
    $next_run = wp_next_scheduled(self::HOOK_NAME);
    $last_run = get_option(self::OPTION_LAST_RUN, '');
    
    return [
      'enabled' => $enabled,
      'scheduled' => (bool) $next_run,
      'next_run' => $next_run ? get_date_from_gmt(date('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s') : '',
      'last_run' => $last_run,
      'hook_name' => self::HOOK_NAME
    ];
  }
  

}

// Add custom cron interval
add_filter('cron_schedules', function($schedules) {
  $schedules['wpmps_15min'] = [
    'interval' => 15 * 60, // 15 minutes
    'display' => __('Cada 15 minutos', 'wp-mp-subscriptions')
  ];
  return $schedules;
});

// Initialize on plugin load
WPMPS_Cron::init();