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
    
    wpmps_log_admin('cron_scheduled', [
      'hook' => self::HOOK_NAME,
      'next_run' => wp_next_scheduled(self::HOOK_NAME)
    ]);
    
    return true;
  }
  
  public static function unschedule() {
    wp_clear_scheduled_hook(self::HOOK_NAME);
    
    wpmps_log_admin('cron_unscheduled', [
      'hook' => self::HOOK_NAME
    ]);
  }
  
  public static function check_subscriptions() {
    $start_time = microtime(true);
    
    wpmps_log_admin('cron_started', [
      'timestamp' => current_time('mysql'),
      'memory_usage' => memory_get_usage(true)
    ]);
    
    // Update last run timestamp
    update_option(self::OPTION_LAST_RUN, current_time('mysql'));
    
    // Get access token
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (empty($token)) {
      wpmps_log_error('cron', 'no_token', 'Missing access token for cron job');
      return;
    }
    
    // Get all users with subscription metadata
    $users = get_users([
      'meta_query' => [
        [
          'key' => '_mp_preapproval_id',
          'compare' => 'EXISTS'
        ]
      ],
      'fields' => ['ID', 'user_email']
    ]);
    
    if (empty($users)) {
      wpmps_log_admin('cron_no_users', [
        'message' => 'No users with subscription metadata found'
      ]);
      return;
    }
    
    $client = new WPMPS_MP_Client($token);
    $processed = 0;
    $updated = 0;
    $errors = 0;
    
    foreach ($users as $user) {
      $user_id = $user->ID;
      $preapproval_id = get_user_meta($user_id, '_mp_preapproval_id', true);
      
      if (empty($preapproval_id)) {
        continue;
      }
      
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
      
      // Fetch current status from MP
      $response = $client->get_preapproval($preapproval_id);
      
      if ($response['http'] !== 200) {
        $errors++;
        wpmps_log_error('cron', 'fetch_failed', 'Failed to fetch preapproval in cron', [
          'user_id' => $user_id,
          'preapproval_id' => $preapproval_id,
          'http_code' => $response['http'] ?? 0
        ]);
        continue;
      }
      
      $data = $response['body'];
      $current_status = sanitize_text_field($data['status'] ?? '');
      $stored_status = get_user_meta($user_id, '_mp_sub_status', true);
      
      // Update last checked timestamp
      update_user_meta($user_id, '_mp_last_checked', current_time('mysql'));
      
      // Check if status changed
      if ($current_status !== $stored_status) {
        $updated++;
        
        // Update stored status
        update_user_meta($user_id, '_mp_sub_status', $current_status);
        
        // Update subscription active flag
        $active = ($current_status === 'authorized') ? 'yes' : 'no';
        update_user_meta($user_id, '_suscripcion_activa', $active);
        
        // Update other metadata
        if (!empty($data['preapproval_plan_id'])) {
          update_user_meta($user_id, '_mp_plan_id', sanitize_text_field($data['preapproval_plan_id']));
        }
        update_user_meta($user_id, '_mp_updated_at', current_time('mysql'));
        
        // Sync user role
        if (function_exists('wpmps_sync_subscription_role')) {
          wpmps_sync_subscription_role($user_id, $current_status);
        }
        
        wpmps_log_subscription('cron_status_changed', [
          'user_id' => $user_id,
          'user_email' => $user->user_email,
          'preapproval_id' => $preapproval_id,
          'old_status' => $stored_status,
          'new_status' => $current_status,
          'active' => $active
        ]);
      }
      
      // Rate limiting: small delay between requests
      usleep(100000); // 0.1 seconds
    }
    
    $end_time = microtime(true);
    $duration = round($end_time - $start_time, 2);
    
    wpmps_log_admin('cron_completed', [
      'duration_seconds' => $duration,
      'users_processed' => $processed,
      'users_updated' => $updated,
      'errors' => $errors,
      'memory_peak' => memory_get_peak_usage(true)
    ]);
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
      'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : '',
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