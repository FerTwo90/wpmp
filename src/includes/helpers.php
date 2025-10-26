<?php
if (!defined('ABSPATH')) exit;

// Plugin contextual helpers ---------------------------------------------------
if (!function_exists('wpmps_plugin_dir_path')) {
  function wpmps_plugin_dir_path(){
    if (defined('WPMPS_DIR')) return WPMPS_DIR;
    if (defined('WPMPS_V2_DIR')) return WPMPS_V2_DIR;
    return trailingslashit(dirname(__DIR__));
  }
}

if (!function_exists('wpmps_plugin_version')) {
  function wpmps_plugin_version(){
    if (defined('WPMPS_VER')) return WPMPS_VER;
    if (defined('WPMPS_V2_VER')) return WPMPS_V2_VER;
    if (defined('WPMPS_VERSION')) return WPMPS_VERSION;
    return '1.0.0';
  }
}

if (!function_exists('wpmps_plugin_main_file')) {
  function wpmps_plugin_main_file(){
    if (defined('WPMPS_MAIN_FILE')) return WPMPS_MAIN_FILE;

    $dir = wpmps_plugin_dir_path();
    $candidates = [
      $dir.'wp-mp-subscriptions.php',
      $dir.'wp-mp-subscriptions-v2.php'
    ];

    foreach ($candidates as $candidate){
      if (file_exists($candidate)){
        if (!defined('WPMPS_MAIN_FILE')) {
          define('WPMPS_MAIN_FILE', $candidate);
        }
        return $candidate;
      }
    }

    return __FILE__;
  }
}

// Ring logger facade: channelized JSON events + optional error_log
if (!function_exists('wpmps_log')) {
  function wpmps_log($channel, $data = []){
    if (!class_exists('WPMPS_Logger')) return;
    // Ensure minimal structure
    $payload = is_array($data) ? $data : ['message' => (string) $data];
    $payload['channel'] = strtoupper($channel);
    WPMPS_Logger::add($payload);
  }
}

// Helper functions for specific log channels
if (!function_exists('wpmps_log_auth')) {
  function wpmps_log_auth($action, $extra = []){
    $data = array_merge(['action' => $action], $extra);
    wpmps_log('AUTH', wpmps_collect_context('auth_' . $action, $data));
  }
}

if (!function_exists('wpmps_log_button')) {
  function wpmps_log_button($action, $extra = []){
    $data = array_merge(['action' => $action], $extra);
    wpmps_log('BUTTON', wpmps_collect_context('button_' . $action, $data));
  }
}

if (!function_exists('wpmps_log_checkout')) {
  function wpmps_log_checkout($action, $extra = []){
    $data = array_merge(['action' => $action], $extra);
    wpmps_log('CHECKOUT', wpmps_collect_context('checkout_' . $action, $data));
  }
}

if (!function_exists('wpmps_log_webhook')) {
  function wpmps_log_webhook($action, $extra = []){
    $data = array_merge(['action' => $action], $extra);
    wpmps_log('WEBHOOK', wpmps_collect_context('webhook_' . $action, $data));
  }
}

if (!function_exists('wpmps_log_subscription')) {
  function wpmps_log_subscription($action, $extra = []){
    $data = array_merge(['action' => $action], $extra);
    wpmps_log('SUBSCRIPTION', wpmps_collect_context('subscription_' . $action, $data));
  }
}

if (!function_exists('wpmps_log_admin')) {
  function wpmps_log_admin($action, $extra = []){
    $data = array_merge(['action' => $action], $extra);
    wpmps_log('ADMIN', wpmps_collect_context('admin_' . $action, $data));
  }
}

if (!function_exists('wpmps_log_error')) {
  function wpmps_log_error($component, $error_code, $message, $extra = []){
    $data = array_merge([
      'component' => $component,
      'error_code' => $error_code,
      'message' => $message
    ], $extra);
    wpmps_log('ERROR', wpmps_collect_context('error_' . $component, $data));
  }
}

// Collect common request/user context for logs
if (!function_exists('wpmps_collect_context')) {
  function wpmps_collect_context($ctx = '', $extra = []){
    $u = wp_get_current_user();
    $is = is_user_logged_in();
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $ref = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
    $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])),0,180) : '';
    $cache = '';
    if (!empty($_SERVER['HTTP_CF_CACHE_STATUS'])) $cache = sanitize_text_field($_SERVER['HTTP_CF_CACHE_STATUS']);
    if (!$cache && !empty($_SERVER['HTTP_X_CACHE'])) $cache = sanitize_text_field($_SERVER['HTTP_X_CACHE']);
    $base = [
      'ts' => gmdate('c'),
      'ctx'=> $ctx,
      'is_user_logged_in' => $is,
      'user_id' => $is ? intval($u->ID) : 0,
      'user_email' => $is ? sanitize_email($u->user_email) : '',
      'uri' => $uri,
      'referer' => $ref,
      'ip' => $ip,
      'ua' => $ua,
      'cache_hint' => $cache,
    ];
    return array_merge($base, is_array($extra) ? $extra : []);
  }
}

if (!function_exists('wpmps_get_access_token')) {
  function wpmps_get_access_token(){
    if (defined('MP_ACCESS_TOKEN') && !empty(MP_ACCESS_TOKEN)) {
      $const_token = trim(MP_ACCESS_TOKEN);
      if (function_exists('wpmps_log_admin')){
        $hash = substr(md5($const_token), 0, 12);
        wpmps_log_admin('token_from_constant', ['token_hash'=>$hash]);
      }
      return $const_token;
    }
    $opt = get_option('wpmps_access_token');
    if (is_string($opt) && $opt !== '') {
      $opt = trim($opt);
      if (function_exists('wpmps_log_admin')){
        $hash = substr(md5($opt), 0, 12);
        wpmps_log_admin('token_from_option', ['token_hash'=>$hash]);
      }
      return $opt;
    }
    if (function_exists('wpmps_log_error')){
      wpmps_log_error('token', 'not_found', 'No access token found in constant or option');
    }
    return '';
  }
}

// Build Mercado Pago subscription checkout URL from plan_id
if (!function_exists('wpmps_extract_plan_id')) {
  function wpmps_extract_plan_id($value){
    $value = trim((string)$value);
    // Case: full URL with preapproval_plan_id param
    if (stripos($value, 'http') === 0){
      $parts = wp_parse_url($value);
      if (!empty($parts['query'])){
        parse_str($parts['query'], $qs);
        if (!empty($qs['preapproval_plan_id'])){
          return sanitize_text_field($qs['preapproval_plan_id']);
        }
      }
    }
    // Case: raw pattern preapproval_plan_id=XYZ pasted (even if repeated)
    if (preg_match('/preapproval_plan_id=([A-Za-z0-9_\-]+)/i', $value, $m)){
      return sanitize_text_field($m[1]);
    }
    // Fallback: assume the value is the id itself (strip stray chars)
    $value = preg_replace('/[^A-Za-z0-9_\-]/', '', $value);
    return sanitize_text_field($value);
  }
}

if (!function_exists('wpmps_mp_checkout_url')) {
  function wpmps_mp_checkout_url($plan_id){
    // Normalize in case a full URL was pasted
    $plan_id = wpmps_extract_plan_id($plan_id);
    // Allow override via option; default hardcoded to Argentina as solicitado
    $domain = trim((string) get_option('wpmps_mp_domain', 'mercadopago.com.ar'));
    $domain = preg_replace('/[^a-z0-9\.-]/i', '', $domain);
    $url = 'https://'.$domain.'/subscriptions/checkout?preapproval_plan_id='.rawurlencode($plan_id);
    return esc_url_raw($url);
  }
}

// Helper: current absolute URL to return to (for login redirect)
if (!function_exists('wpmps_current_url')) {
  function wpmps_current_url(){
    $scheme = is_ssl() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? parse_url(home_url(), PHP_URL_HOST);
    $req  = $_SERVER['REQUEST_URI'] ?? '/';
    $host = sanitize_text_field($host);
    $req  = sanitize_text_field($req);
    return $scheme.'://'.$host.$req;
  }
}

if (!function_exists('wpmps_sync_subscription_role')) {
  function wpmps_sync_subscription_role($user_id, $status){
    $user_id = intval($user_id);
    if ($user_id <= 0) {
      return false;
    }

    $role_option = get_option('wpmps_role_on_authorized', '');
    if ($role_option === 1 || $role_option === '1') {
      $role_option = 'suscriptor_premium';
    }

    if (!is_string($role_option)) {
      $role_option = '';
    } else {
      $role_option = trim($role_option);
    }

    if ($role_option === '') {
      return false;
    }

    if (!get_role($role_option) && $role_option === 'suscriptor_premium') {
      add_role($role_option, __('Suscriptor Premium','wp-mp-subscriptions'), ['read'=>true]);
    }

    $wp_user = new WP_User($user_id);
    if (!$wp_user || !($wp_user instanceof WP_User)) {
      return false;
    }

    $should_assign = in_array($status, ['authorized','yes','active', true, 1, '1'], true);

    $roles_before = array_values($wp_user->roles);

    if ($should_assign) {
      // Replace any current roles with the configured one.
      $wp_user->set_role($role_option);
    } else {
      // Subscription is not active - set to pending role
      if (in_array($role_option, $roles_before, true)) {
        $wp_user->remove_role($role_option);
      }
      
      // Set to pending role for inactive subscriptions
      if (!get_role('pending')) {
        add_role('pending', __('Pendiente','wp-mp-subscriptions'), ['read'=>true]);
      }
      
      // Always set to pending when subscription is not active
      $wp_user->set_role('pending');
    }

    $roles_after = array_values($wp_user->roles);

    if (function_exists('clean_user_cache')) {
      clean_user_cache($user_id);
    }
    wp_cache_delete($user_id, 'user_meta');

    $mail_sent = null;
    $mail_enabled = get_option('wpmps_mail_enabled', '');
    if ($mail_enabled === 'yes' || $mail_enabled === '1') {
      $user_email = sanitize_email($wp_user->user_email);
      if ($user_email) {
        $subject_tpl = get_option('wpmps_mail_subject', '');
        $body_tpl    = get_option('wpmps_mail_body', '');
        if ($subject_tpl !== '' && $body_tpl !== '') {
          $vars = [
            'user_email'    => $wp_user->user_email,
            'user_login'    => $wp_user->user_login,
            'user_display'  => $wp_user->display_name,
            'preapproval_id'=> get_user_meta($user_id, '_mp_preapproval_id', true),
            'plan_name'     => get_user_meta($user_id, '_mp_plan_id', true),
            'status'        => $status,
          ];
          $mail_sent = wpmps_send_test_mail($user_email, $subject_tpl, $body_tpl, $vars);
        } else {
          $mail_sent = false;
        }
      }
    }

    if (function_exists('wpmps_log_subscription')) {
      wpmps_log_subscription('role_sync', [
        'user_id' => $user_id,
        'role' => $role_option,
        'status' => is_scalar($status) ? (string) $status : '',
        'assigned' => $should_assign ? 'yes' : 'no',
        'roles_before' => $roles_before,
        'roles_after' => $roles_after,
        'mail_sent' => is_null($mail_sent) ? '' : ($mail_sent ? 'yes' : 'no'),
      ]);
    }

    return true;
  }
}

if (!function_exists('wpmps_mail_render_template')) {
  function wpmps_mail_render_template($subject, $body, $vars = []){
    $subject = is_string($subject) ? $subject : '';
    $body    = is_string($body) ? $body : '';
    $vars    = is_array($vars) ? $vars : [];

    // Check if we're using HTML format
    $format = get_option('wpmps_mail_format', 'text');
    $is_html = ($format === 'html');

    $subject_map = [];
    $body_map = [];
    foreach ($vars as $key => $value) {
      $placeholder = '{'.sanitize_key($key).'}';
      if ($placeholder === '{}') {
        continue;
      }
      if (is_array($value) || is_object($value)) {
        $value = '';
      }
      $value = (string) $value;
      $subject_map[$placeholder] = sanitize_text_field($value);
      
      // For HTML emails, preserve HTML in variables but escape user input
      if ($is_html && in_array($key, ['action_url'])) {
        // URLs should be escaped for HTML
        $body_map[$placeholder] = esc_url($value);
      } elseif ($is_html && in_array($key, ['user_display', 'plan_name'])) {
        // User content should be escaped for HTML
        $body_map[$placeholder] = esc_html($value);
      } else {
        // Default sanitization
        $body_map[$placeholder] = $is_html ? esc_html($value) : sanitize_textarea_field($value);
      }
    }

    if (!empty($subject_map)) {
      $subject = strtr($subject, $subject_map);
    }
    if (!empty($body_map)) {
      $body = strtr($body, $body_map);
    }

    return [
      'subject' => sanitize_text_field($subject),
      'body'    => $is_html ? wp_kses_post($body) : sanitize_textarea_field($body),
    ];
  }
}

if (!function_exists('wpmps_send_test_mail')) {
  function wpmps_send_test_mail($to, $subject, $body, $vars = []){
    $email = sanitize_email($to);
    if (!$email || !is_email($email)) {
      return false;
    }

    $rendered = wpmps_mail_render_template($subject, $body, $vars);
    
    // Check mail format setting
    $format = get_option('wpmps_mail_format', 'text');
    $headers = [];
    
    // Set custom sender from settings
    $from_name = get_option('wpmps_mail_from_name', 'Hoy Salgo');
    $from_email = get_option('wpmps_mail_from_email', 'info@hoysalgo.com');
    
    // Validate email
    if (!is_email($from_email)) {
      $from_email = 'info@hoysalgo.com';
    }
    
    $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    $headers[] = 'Reply-To: ' . $from_email;
    
    if ($format === 'html') {
      $headers[] = 'Content-Type: text/html; charset=UTF-8';
    } else {
      $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    }

    $result = wp_mail($email, $rendered['subject'], $rendered['body'], $headers);

    if (function_exists('wpmps_log_admin')) {
      wpmps_log_admin('mail_sent', [
        'email' => $email,
        'subject' => $rendered['subject'],
        'format' => $format,
        'sent' => $result ? 'yes' : 'no',
      ]);
    }

    return (bool) $result;
  }
}

// PostMessage validation and processing functions for checkout tracking

if (!function_exists('wpmps_validate_postmessage_origin')) {
  /**
   * Validate postMessage origin against configured Mercado Pago domain
   * 
   * @param string $origin The origin from the postMessage event
   * @return bool True if origin is valid, false otherwise
   */
  function wpmps_validate_postmessage_origin($origin) {
    if (empty($origin) || !is_string($origin)) {
      return false;
    }

    // Get configured MP domain from settings
    $mp_domain = get_option('wpmps_mp_domain', 'mercadopago.com.ar');
    $mp_domain = trim($mp_domain);
    
    if (empty($mp_domain)) {
      return false;
    }

    // Parse the origin URL
    $parsed_origin = wp_parse_url($origin);
    if (!$parsed_origin || empty($parsed_origin['host'])) {
      return false;
    }

    $origin_host = $parsed_origin['host'];
    
    // Check if origin host matches or is a subdomain of the configured MP domain
    // Allow exact match or subdomain (e.g., www.mercadopago.com.ar)
    return ($origin_host === $mp_domain || str_ends_with($origin_host, '.' . $mp_domain));
  }
}

if (!function_exists('wpmps_extract_preapproval_id')) {
  /**
   * Extract preapproval_id from postMessage payload
   * 
   * @param mixed $data The data from the postMessage event
   * @return string|false The preapproval_id if found, false otherwise
   */
  function wpmps_extract_preapproval_id($data) {
    if (empty($data)) {
      return false;
    }

    // Handle JSON string data
    if (is_string($data)) {
      $decoded = json_decode($data, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $data = $decoded;
      }
    }

    // Handle array/object data
    if (is_array($data) || is_object($data)) {
      $data = (array) $data;
      
      // Look for preapproval_id in common locations
      $possible_keys = [
        'preapproval_id',
        'preapprovalId',
        'subscription_id',
        'subscriptionId',
        'id'
      ];

      foreach ($possible_keys as $key) {
        if (isset($data[$key]) && !empty($data[$key])) {
          $id = sanitize_text_field($data[$key]);
          // Validate format: MP preapproval IDs are typically alphanumeric with dashes
          if (preg_match('/^[A-Za-z0-9_\-]+$/', $id) && strlen($id) > 5) {
            return $id;
          }
        }
      }

      // Look for nested data structures
      if (isset($data['payment']) && is_array($data['payment'])) {
        return wpmps_extract_preapproval_id($data['payment']);
      }

      if (isset($data['subscription']) && is_array($data['subscription'])) {
        return wpmps_extract_preapproval_id($data['subscription']);
      }
    }

    // Handle string data that might contain the ID directly
    if (is_string($data)) {
      $data = sanitize_text_field($data);
      // Check if the string itself looks like a preapproval ID
      if (preg_match('/^[A-Za-z0-9_\-]+$/', $data) && strlen($data) > 5) {
        return $data;
      }
    }

    return false;
  }
}

if (!function_exists('wpmps_determine_postmessage_action')) {
  /**
   * Determine action and response based on preapproval status
   * Implements requirement 5.4: communicate state in container page
   * 
   * @param string $status The preapproval status from MP API
   * @param string $preapproval_id The preapproval ID
   * @param int $user_id The WordPress user ID
   * @return array Action data with message, modal_action, and redirect info
   */
  function wpmps_determine_postmessage_action($status, $preapproval_id, $user_id) {
    $status = sanitize_text_field($status);
    $preapproval_id = sanitize_text_field($preapproval_id);
    $user_id = intval($user_id);
    
    // Default success destination
    $success_destination = apply_filters('wpmps_finalization_redirect', home_url('/cartelera/'));
    
    // Initialize response structure with all required fields for frontend communication
    $action_data = [
      'status' => $status,
      'modal_action' => 'close', // Always close modal (requirement 1.4 and 5.4)
      'message' => '',
      'message_type' => 'info',
      'redirect_url' => null,
      'should_redirect' => false,
      'preapproval_id' => $preapproval_id,
      'user_id' => $user_id,
      'timestamp' => current_time('mysql'),
      'communication_method' => 'postmessage' // Indicate this is for postMessage communication
    ];
    
    // Determine action based on status
    switch ($status) {
      case 'authorized':
        // Requirement 1.5: Show success message for authorized status
        $action_data['message'] = __('¡Suscripción autorizada exitosamente!', 'wp-mp-subscriptions');
        $action_data['message_type'] = 'success';
        $action_data['should_redirect'] = true;
        $action_data['redirect_url'] = add_query_arg([
          'ok' => 1, 
          'mp_status' => $status
        ], $success_destination);
        break;
        
      case 'pending':
        // Requirement 1.6: Show notification message for non-authorized status
        $action_data['message'] = __('Tu suscripción está siendo procesada. Te notificaremos por email cuando esté autorizada.', 'wp-mp-subscriptions');
        $action_data['message_type'] = 'info';
        $action_data['should_redirect'] = false;
        break;
        
      case 'cancelled':
      case 'canceled':
        $action_data['message'] = __('La suscripción fue cancelada. Puedes intentar nuevamente si lo deseas.', 'wp-mp-subscriptions');
        $action_data['message_type'] = 'warning';
        $action_data['should_redirect'] = false;
        break;
        
      case 'paused':
        $action_data['message'] = __('Tu suscripción está pausada. Te notificaremos por email sobre cualquier cambio.', 'wp-mp-subscriptions');
        $action_data['message_type'] = 'info';
        $action_data['should_redirect'] = false;
        break;
        
      default:
        // Handle unknown or other statuses
        $action_data['message'] = sprintf(
          __('Tu suscripción tiene estado "%s". Te notificaremos por email sobre cualquier cambio.', 'wp-mp-subscriptions'),
          $status
        );
        $action_data['message_type'] = 'info';
        $action_data['should_redirect'] = false;
        break;
    }
    
    // Add additional communication data for frontend
    $action_data['display_duration'] = $action_data['message_type'] === 'success' ? 3000 : 
                                      ($action_data['message_type'] === 'error' ? 7000 : 5000);
    
    // Add redirect delay for better UX
    if ($action_data['should_redirect']) {
      $action_data['redirect_delay'] = 2000; // 2 seconds to show message
    }
    
    // Log the action determination with communication details
    wpmps_log_subscription('action_determined', [
      'preapproval_id' => $preapproval_id,
      'status' => $status,
      'user_id' => $user_id,
      'modal_action' => $action_data['modal_action'],
      'message_type' => $action_data['message_type'],
      'should_redirect' => $action_data['should_redirect'],
      'redirect_url' => $action_data['redirect_url'],
      'communication_method' => $action_data['communication_method'],
      'display_duration' => $action_data['display_duration']
    ]);
    
    return $action_data;
  }
}

if (!function_exists('wpmps_log_modal_action')) {
  /**
   * Log modal action and state changes for monitoring
   * 
   * @param string $action The action taken (close, redirect, message_shown)
   * @param array $context Additional context data
   */
  function wpmps_log_modal_action($action, $context = []) {
    $action = sanitize_text_field($action);
    $context = is_array($context) ? $context : [];
    
    $log_data = array_merge([
      'action' => $action,
      'timestamp' => current_time('mysql'),
      'user_id' => get_current_user_id(),
      'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 200) : '',
      'referer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : ''
    ], $context);
    
    wpmps_log_subscription('modal_action', $log_data);
  }
}

if (!function_exists('wpmps_validate_preapproval_status')) {
  /**
   * Validate and normalize preapproval status from MP API
   * 
   * @param string $status Raw status from MP API
   * @return string Normalized status
   */
  function wpmps_validate_preapproval_status($status) {
    $status = strtolower(trim(sanitize_text_field($status)));
    
    // Map of known MP statuses to normalized values
    $status_map = [
      'authorized' => 'authorized',
      'pending' => 'pending',
      'cancelled' => 'cancelled',
      'canceled' => 'cancelled', // Handle US spelling
      'paused' => 'paused',
      'active' => 'authorized', // Some APIs might return 'active'
      'inactive' => 'cancelled',
      'suspended' => 'paused'
    ];
    
    $normalized = isset($status_map[$status]) ? $status_map[$status] : $status;
    
    // Log status normalization if it changed
    if ($normalized !== $status) {
      wpmps_log_subscription('status_normalized', [
        'original_status' => $status,
        'normalized_status' => $normalized
      ]);
    }
    
    return $normalized;
  }
}

if (!function_exists('wpmps_create_error_response')) {
  /**
   * Create standardized error response for postMessage communication
   * Ensures modal closure and proper error communication (requirement 5.4)
   * 
   * @param string $error_message The error message to display
   * @param string $error_code Optional error code for logging
   * @param array $additional_data Additional error context
   * @return array Standardized error response
   */
  function wpmps_create_error_response($error_message, $error_code = 'unknown', $additional_data = []) {
    $error_message = sanitize_text_field($error_message);
    $error_code = sanitize_text_field($error_code);
    
    $error_response = [
      'status' => 'error',
      'modal_action' => 'close', // Always close modal even on error (requirement 5.4)
      'message' => $error_message ?: __('Error procesando la suscripción. Intenta nuevamente.', 'wp-mp-subscriptions'),
      'message_type' => 'error',
      'redirect_url' => null,
      'should_redirect' => false,
      'error_code' => $error_code,
      'timestamp' => current_time('mysql'),
      'communication_method' => 'postmessage',
      'display_duration' => 7000, // Longer display for errors
      'user_id' => get_current_user_id()
    ];
    
    // Merge additional error data
    if (is_array($additional_data)) {
      $error_response = array_merge($error_response, $additional_data);
    }
    
    // Log the error response creation
    wpmps_log_error('postmessage_communication', $error_code, $error_message, [
      'error_response' => $error_response,
      'additional_data' => $additional_data
    ]);
    
    return $error_response;
  }
}

if (!function_exists('wpmps_ensure_modal_closure')) {
  /**
   * Ensure modal closure instructions are included in response
   * Implements requirement 5.4: modal closes independently of status
   * 
   * @param array $response_data The response data to modify
   * @return array Modified response with modal closure instructions
   */
  function wpmps_ensure_modal_closure($response_data) {
    if (!is_array($response_data)) {
      $response_data = [];
    }
    
    // Always ensure modal_action is set to close
    $response_data['modal_action'] = 'close';
    
    // Add modal closure instructions for frontend
    $response_data['modal_closure'] = [
      'force_close' => true,
      'close_methods' => ['element_hiding', 'postmessage', 'esc_key', 'close_buttons'],
      'close_timeout' => 1000, // Max time to wait for closure
      'verify_closure' => true
    ];
    
    // Log modal closure enforcement
    wpmps_log_modal_action('closure_enforced', [
      'response_status' => $response_data['status'] ?? 'unknown',
      'message_type' => $response_data['message_type'] ?? 'unknown',
      'force_close' => true
    ]);
    
    return $response_data;
  }
}

if (!function_exists('wpmps_handle_api_failure_fallback')) {
  /**
   * Handle API failure gracefully by storing preapproval_id for later sync
   * Implements requirement 6.1: graceful API error handling with fallback storage
   * 
   * @param string $preapproval_id The preapproval ID to store
   * @param int $user_id The WordPress user ID
   * @param int $http_code The HTTP response code from the failed API call
   * @param string $source The source of the capture (postmessage/traditional)
   * @param string $origin The origin URL or identifier
   * @param array $error_context Additional error context for logging
   * @return array Result of fallback storage operation
   */
  function wpmps_handle_api_failure_fallback($preapproval_id, $user_id, $http_code, $source, $origin, $error_context = []) {
    $preapproval_id = sanitize_text_field($preapproval_id);
    $user_id = intval($user_id);
    $http_code = intval($http_code);
    $source = sanitize_text_field($source);
    $origin = sanitize_text_field($origin);
    
    $fallback_result = [
      'stored' => false,
      'metadata_updated' => false,
      'cron_flag_set' => false,
      'user_notified' => false
    ];
    
    // Only proceed if we have valid data
    if (empty($preapproval_id) || $user_id <= 0) {
      wpmps_log_error('api_fallback', 'invalid_data', 'Cannot store fallback data: invalid preapproval_id or user_id', [
        'preapproval_id' => $preapproval_id,
        'user_id' => $user_id,
        'http_code' => $http_code,
        'source' => $source
      ]);
      return $fallback_result;
    }
    
    try {
      // Store preapproval_id and mark as needing API retry
      update_user_meta($user_id, '_mp_preapproval_id', $preapproval_id);
      update_user_meta($user_id, '_mp_sub_status', 'api_error');
      update_user_meta($user_id, '_mp_api_error_code', $http_code);
      update_user_meta($user_id, '_mp_api_error_time', current_time('mysql'));
      update_user_meta($user_id, '_mp_updated_at', current_time('mysql'));
      update_user_meta($user_id, '_mp_needs_sync', 'yes'); // Flag for cron sync
      
      $fallback_result['stored'] = true;
      $fallback_result['metadata_updated'] = true;
      $fallback_result['cron_flag_set'] = true;
      
      // Log successful fallback storage
      wpmps_log_subscription('api_failure_fallback_stored', array_merge([
        'preapproval_id' => $preapproval_id,
        'user_id' => $user_id,
        'http_code' => $http_code,
        'source' => $source,
        'origin' => $origin,
        'fallback_result' => $fallback_result,
        'storage_time' => current_time('mysql')
      ], $error_context));
      
      // Set user to pending role since we can't verify status
      if (function_exists('wpmps_sync_subscription_role')) {
        wpmps_sync_subscription_role($user_id, 'pending');
      }
      
      // Optionally notify user about the delay (if email notifications are enabled)
      $mail_enabled = get_option('wpmps_mail_enabled', '');
      if (($mail_enabled === 'yes' || $mail_enabled === '1') && function_exists('wpmps_send_api_error_notification')) {
        $fallback_result['user_notified'] = wpmps_send_api_error_notification($user_id, $preapproval_id);
      }
      
    } catch (Exception $e) {
      wpmps_log_error('api_fallback', 'storage_exception', 'Exception during fallback storage', [
        'preapproval_id' => $preapproval_id,
        'user_id' => $user_id,
        'exception_message' => $e->getMessage(),
        'http_code' => $http_code,
        'source' => $source
      ]);
    }
    
    return $fallback_result;
  }
}

if (!function_exists('wpmps_create_api_failure_response')) {
  /**
   * Create user-friendly response for API failures
   * Implements requirement 6.1: graceful error handling without blocking flow
   * 
   * @param string $preapproval_id The preapproval ID that failed
   * @param int $http_code The HTTP error code
   * @param string $source The source of the capture
   * @param array $fallback_result Result of fallback storage operation
   * @return array Standardized response for API failures
   */
  function wpmps_create_api_failure_response($preapproval_id, $http_code, $source, $fallback_result) {
    $preapproval_id = sanitize_text_field($preapproval_id);
    $http_code = intval($http_code);
    $source = sanitize_text_field($source);
    
    // Determine user-friendly message based on error type
    $user_message = '';
    $message_type = 'info';
    
    if ($http_code >= 500) {
      // Server errors - temporary issue
      $user_message = __('Mercado Pago está experimentando problemas temporales. Tu suscripción se procesará automáticamente y te notificaremos por email.', 'wp-mp-subscriptions');
    } elseif ($http_code === 404) {
      // Not found - might be processing delay
      $user_message = __('Tu suscripción está siendo procesada. Te notificaremos por email cuando esté lista.', 'wp-mp-subscriptions');
    } elseif ($http_code === 401 || $http_code === 403) {
      // Auth errors - configuration issue but don't alarm user
      $user_message = __('Estamos verificando tu suscripción. Te notificaremos por email sobre el estado.', 'wp-mp-subscriptions');
    } else {
      // Generic error
      $user_message = __('Tu suscripción se está procesando. Te notificaremos por email cuando esté lista.', 'wp-mp-subscriptions');
    }
    
    // Create response that ensures modal closure and provides feedback
    $response = [
      'status' => 'processing', // Not 'error' to avoid alarming user
      'modal_action' => 'close', // Always close modal (requirement 5.4)
      'message' => $user_message,
      'message_type' => $message_type,
      'redirect_url' => null,
      'should_redirect' => false,
      'preapproval_id' => $preapproval_id,
      'error_code' => 'api_temporary_failure',
      'http_code' => $http_code,
      'fallback_stored' => $fallback_result['stored'] ?? false,
      'will_retry' => true,
      'notification_method' => 'email',
      'timestamp' => current_time('mysql'),
      'communication_method' => 'postmessage',
      'display_duration' => 6000, // Longer display for important info
      'user_id' => get_current_user_id()
    ];
    
    // Log the graceful error response creation
    wpmps_log_subscription('api_failure_response_created', [
      'preapproval_id' => $preapproval_id,
      'http_code' => $http_code,
      'source' => $source,
      'response_status' => $response['status'],
      'message_type' => $response['message_type'],
      'fallback_result' => $fallback_result,
      'user_message_length' => strlen($user_message)
    ]);
    
    return $response;
  }
}

if (!function_exists('wpmps_send_api_error_notification')) {
  /**
   * Send notification to user about API error and delayed processing
   * 
   * @param int $user_id WordPress user ID
   * @param string $preapproval_id The preapproval ID being processed
   * @return bool True if notification sent successfully
   */
  function wpmps_send_api_error_notification($user_id, $preapproval_id) {
    $user_id = intval($user_id);
    $preapproval_id = sanitize_text_field($preapproval_id);
    
    if ($user_id <= 0) {
      return false;
    }
    
    $user = get_user_by('ID', $user_id);
    if (!$user || !is_email($user->user_email)) {
      return false;
    }
    
    // Check if we have email templates configured
    $subject_template = get_option('wpmps_mail_subject_api_error', '');
    $body_template = get_option('wpmps_mail_body_api_error', '');
    
    // Use default templates if not configured
    if (empty($subject_template)) {
      $subject_template = __('Tu suscripción está siendo procesada', 'wp-mp-subscriptions');
    }
    
    if (empty($body_template)) {
      $body_template = __('Hola {user_display},

Hemos recibido tu suscripción (ID: {preapproval_id}) y la estamos procesando.

Debido a un retraso temporal en la verificación, te notificaremos por email tan pronto como tu suscripción esté confirmada.

Gracias por tu paciencia.

Saludos,
El equipo de Hoy Salgo', 'wp-mp-subscriptions');
    }
    
    // Prepare template variables
    $vars = [
      'user_email' => $user->user_email,
      'user_login' => $user->user_login,
      'user_display' => $user->display_name ?: $user->user_login,
      'preapproval_id' => $preapproval_id,
      'site_name' => get_bloginfo('name'),
      'site_url' => home_url(),
      'timestamp' => current_time('mysql')
    ];
    
    // Send the notification
    $sent = wpmps_send_test_mail($user->user_email, $subject_template, $body_template, $vars);
    
    // Log the notification attempt
    wpmps_log_subscription('api_error_notification_sent', [
      'user_id' => $user_id,
      'user_email' => $user->user_email,
      'preapproval_id' => $preapproval_id,
      'notification_sent' => $sent,
      'template_used' => !empty(get_option('wpmps_mail_subject_api_error', '')) ? 'custom' : 'default'
    ]);
    
    return $sent;
  }
}

if (!function_exists('wpmps_retry_failed_api_calls')) {
  /**
   * Retry failed API calls for users marked as needing sync
   * This function can be called by cron or manually to process failed API calls
   * 
   * @param int $limit Maximum number of retries to process in one batch
   * @return array Results of retry operations
   */
  function wpmps_retry_failed_api_calls($limit = 10) {
    $limit = intval($limit);
    if ($limit <= 0) {
      $limit = 10;
    }
    
    // Find users who need API retry
    $users_needing_sync = get_users([
      'meta_query' => [
        [
          'key' => '_mp_needs_sync',
          'value' => 'yes',
          'compare' => '='
        ]
      ],
      'number' => $limit,
      'fields' => 'ID'
    ]);
    
    $results = [
      'processed' => 0,
      'successful' => 0,
      'failed' => 0,
      'details' => []
    ];
    
    if (empty($users_needing_sync)) {
      wpmps_log_subscription('api_retry_batch', [
        'users_found' => 0,
        'message' => 'No users needing API retry found'
      ]);
      return $results;
    }
    
    wpmps_log_subscription('api_retry_batch_started', [
      'users_found' => count($users_needing_sync),
      'limit' => $limit
    ]);
    
    foreach ($users_needing_sync as $user_id) {
      $results['processed']++;
      
      $preapproval_id = get_user_meta($user_id, '_mp_preapproval_id', true);
      if (empty($preapproval_id)) {
        $results['failed']++;
        continue;
      }
      
      // Attempt to process the preapproval again
      $retry_result = wpmps_process_preapproval_capture($preapproval_id, 'cron_retry', 'cron_retry');
      
      if ($retry_result['success']) {
        // Clear the retry flag
        delete_user_meta($user_id, '_mp_needs_sync');
        delete_user_meta($user_id, '_mp_api_error_code');
        delete_user_meta($user_id, '_mp_api_error_time');
        
        $results['successful']++;
        $results['details'][] = [
          'user_id' => $user_id,
          'preapproval_id' => $preapproval_id,
          'status' => 'success',
          'final_status' => $retry_result['data']['status'] ?? 'unknown'
        ];
      } else {
        $results['failed']++;
        $results['details'][] = [
          'user_id' => $user_id,
          'preapproval_id' => $preapproval_id,
          'status' => 'failed',
          'error' => $retry_result['data']['error_code'] ?? 'unknown'
        ];
      }
    }
    
    wpmps_log_subscription('api_retry_batch_completed', $results);
    
    return $results;
  }
}

if (!function_exists('wpmps_detect_postmessage_failure')) {
  /**
   * Detect and log postMessage failures for monitoring
   * Implements requirement 6.2: detect when postMessage doesn't work
   * 
   * @param string $failure_type Type of failure (timeout, checkout_closed, etc.)
   * @param array $context Additional context about the failure
   * @return bool True if failure was logged successfully
   */
  function wpmps_detect_postmessage_failure($failure_type, $context = []) {
    $failure_type = sanitize_text_field($failure_type);
    $context = is_array($context) ? $context : [];
    
    // Add common failure context
    $failure_context = array_merge([
      'failure_type' => $failure_type,
      'detection_time' => current_time('mysql'),
      'user_id' => get_current_user_id(),
      'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 200) : '',
      'referer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '',
      'page_url' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : ''
    ], $context);
    
    // Log the postMessage failure (requirement 4.2, 4.3)
    wpmps_log_error('postmessage_fallback', $failure_type, 'PostMessage failure detected', $failure_context);
    
    // Also log to subscription channel for monitoring
    wpmps_log_subscription('postmessage_failure_detected', $failure_context);
    
    // Check if we should send admin notification for repeated failures
    $failure_count = get_transient('wpmps_postmessage_failures_' . $failure_type);
    if ($failure_count === false) {
      $failure_count = 0;
    }
    $failure_count++;
    set_transient('wpmps_postmessage_failures_' . $failure_type, $failure_count, HOUR_IN_SECONDS);
    
    // Send admin notification if failures are frequent (more than 5 in an hour)
    if ($failure_count >= 5 && function_exists('wpmps_send_admin_notification')) {
      wpmps_send_admin_notification('postmessage_failures', [
        'failure_type' => $failure_type,
        'failure_count' => $failure_count,
        'context' => $failure_context
      ]);
    }
    
    return true;
  }
}

if (!function_exists('wpmps_ensure_traditional_flow_works')) {
  /**
   * Ensure traditional back_url flow continues working when postMessage fails
   * Implements requirement 4.2, 4.3: traditional flow as fallback
   * 
   * @param int $user_id WordPress user ID
   * @param string $failure_reason Reason for postMessage failure
   * @return array Status of traditional flow preparation
   */
  function wpmps_ensure_traditional_flow_works($user_id, $failure_reason = 'unknown') {
    $user_id = intval($user_id);
    $failure_reason = sanitize_text_field($failure_reason);
    
    $result = [
      'traditional_flow_ready' => false,
      'back_url_accessible' => false,
      'user_session_valid' => false,
      'fallback_prepared' => false
    ];
    
    // Check if user session is valid
    if ($user_id > 0 && get_user_by('ID', $user_id)) {
      $result['user_session_valid'] = true;
    }
    
    // Check if back_url (finalization page) is accessible
    $finalization_url = home_url('/finalizar-suscripcion');
    $result['back_url_accessible'] = !empty($finalization_url);
    
    // Prepare fallback metadata for user
    if ($user_id > 0) {
      update_user_meta($user_id, '_mp_postmessage_failed', 'yes');
      update_user_meta($user_id, '_mp_postmessage_failure_reason', $failure_reason);
      update_user_meta($user_id, '_mp_postmessage_failure_time', current_time('mysql'));
      update_user_meta($user_id, '_mp_fallback_to_traditional', 'yes');
      
      $result['fallback_prepared'] = true;
    }
    
    // Traditional flow is ready if back_url is accessible and user session is valid
    $result['traditional_flow_ready'] = $result['back_url_accessible'] && $result['user_session_valid'];
    
    // Log traditional flow preparation
    wpmps_log_subscription('traditional_flow_fallback_prepared', array_merge($result, [
      'user_id' => $user_id,
      'failure_reason' => $failure_reason,
      'finalization_url' => $finalization_url
    ]));
    
    return $result;
  }
}

if (!function_exists('wpmps_send_admin_notification')) {
  /**
   * Send notification to admin about system issues
   * 
   * @param string $notification_type Type of notification
   * @param array $data Notification data
   * @return bool True if notification sent successfully
   */
  function wpmps_send_admin_notification($notification_type, $data = []) {
    $notification_type = sanitize_text_field($notification_type);
    $data = is_array($data) ? $data : [];
    
    // Get admin email
    $admin_email = get_option('admin_email');
    if (!is_email($admin_email)) {
      return false;
    }
    
    // Check if admin notifications are enabled
    $notifications_enabled = get_option('wpmps_admin_notifications_enabled', 'no');
    if ($notifications_enabled !== 'yes') {
      return false;
    }
    
    // Prepare notification content based on type
    $subject = '';
    $body = '';
    
    switch ($notification_type) {
      case 'postmessage_failures':
        $subject = '[' . get_bloginfo('name') . '] Fallos frecuentes de PostMessage detectados';
        $body = "Se han detectado múltiples fallos de PostMessage en el sistema de suscripciones.\n\n";
        $body .= "Tipo de fallo: " . ($data['failure_type'] ?? 'desconocido') . "\n";
        $body .= "Cantidad de fallos: " . ($data['failure_count'] ?? 'desconocido') . "\n";
        $body .= "Tiempo: " . current_time('mysql') . "\n\n";
        $body .= "Esto podría indicar problemas de compatibilidad con Mercado Pago o problemas de conectividad.\n";
        $body .= "El sistema continuará funcionando usando el flujo tradicional como respaldo.\n\n";
        $body .= "Sitio: " . home_url() . "\n";
        break;
        
      default:
        $subject = '[' . get_bloginfo('name') . '] Notificación del sistema de suscripciones';
        $body = "Se ha producido un evento en el sistema de suscripciones.\n\n";
        $body .= "Tipo: " . $notification_type . "\n";
        $body .= "Tiempo: " . current_time('mysql') . "\n";
        $body .= "Datos: " . wp_json_encode($data) . "\n";
        break;
    }
    
    // Send notification
    $sent = wp_mail($admin_email, $subject, $body);
    
    // Log notification attempt
    wpmps_log_admin('notification_sent', [
      'notification_type' => $notification_type,
      'admin_email' => $admin_email,
      'sent' => $sent,
      'subject' => $subject
    ]);
    
    return $sent;
  }
}

if (!function_exists('wpmps_get_postmessage_fallback_stats')) {
  /**
   * Get statistics about postMessage fallback usage
   * 
   * @param string $period Time period ('hour', 'day', 'week')
   * @return array Fallback statistics
   */
  function wpmps_get_postmessage_fallback_stats($period = 'day') {
    $period = sanitize_text_field($period);
    
    $stats = [
      'period' => $period,
      'total_failures' => 0,
      'failure_types' => [],
      'traditional_flow_usage' => 0,
      'success_rate' => 0
    ];
    
    // Get failure counts from transients
    $failure_types = ['timeout', 'checkout_closed', 'origin_invalid', 'extraction_failed'];
    
    foreach ($failure_types as $type) {
      $count = get_transient('wpmps_postmessage_failures_' . $type);
      if ($count !== false) {
        $stats['failure_types'][$type] = intval($count);
        $stats['total_failures'] += intval($count);
      } else {
        $stats['failure_types'][$type] = 0;
      }
    }
    
    // Get traditional flow usage (users with fallback flag)
    $traditional_users = get_users([
      'meta_query' => [
        [
          'key' => '_mp_fallback_to_traditional',
          'value' => 'yes',
          'compare' => '='
        ]
      ],
      'count_total' => true,
      'fields' => 'ID'
    ]);
    
    $stats['traditional_flow_usage'] = is_array($traditional_users) ? count($traditional_users) : 0;
    
    // Calculate success rate (rough estimate)
    $total_attempts = $stats['total_failures'] + $stats['traditional_flow_usage'];
    if ($total_attempts > 0) {
      $stats['success_rate'] = round((1 - ($stats['total_failures'] / $total_attempts)) * 100, 2);
    } else {
      $stats['success_rate'] = 100; // No failures recorded
    }
    
    return $stats;
  }
}

if (!function_exists('wpmps_sanitize_postmessage_data')) {
  /**
   * Sanitize data received from postMessage
   * 
   * @param mixed $data The raw data from postMessage
   * @return array Sanitized data array
   */
  function wpmps_sanitize_postmessage_data($data) {
    $sanitized = [];

    if (empty($data)) {
      return $sanitized;
    }

    // Handle JSON string data
    if (is_string($data)) {
      $decoded = json_decode($data, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $data = $decoded;
      } else {
        // If not JSON, treat as plain string
        $sanitized['raw_data'] = sanitize_text_field($data);
        return $sanitized;
      }
    }

    // Handle array/object data
    if (is_array($data) || is_object($data)) {
      $data = (array) $data;
      
      // Sanitize common fields
      $string_fields = [
        'type',
        'action',
        'status',
        'preapproval_id',
        'preapprovalId',
        'subscription_id',
        'subscriptionId',
        'id',
        'payment_id',
        'paymentId',
        'merchant_order_id',
        'merchantOrderId'
      ];

      foreach ($string_fields as $field) {
        if (isset($data[$field])) {
          $sanitized[$field] = sanitize_text_field($data[$field]);
        }
      }

      // Sanitize numeric fields
      $numeric_fields = [
        'amount',
        'transaction_amount',
        'transactionAmount'
      ];

      foreach ($numeric_fields as $field) {
        if (isset($data[$field]) && is_numeric($data[$field])) {
          $sanitized[$field] = floatval($data[$field]);
        }
      }

      // Sanitize boolean fields
      $boolean_fields = [
        'success',
        'approved',
        'authorized'
      ];

      foreach ($boolean_fields as $field) {
        if (isset($data[$field])) {
          $sanitized[$field] = (bool) $data[$field];
        }
      }

      // Handle nested objects (sanitize one level deep)
      $nested_fields = [
        'payment',
        'subscription',
        'payer',
        'metadata'
      ];

      foreach ($nested_fields as $field) {
        if (isset($data[$field]) && is_array($data[$field])) {
          $sanitized[$field] = [];
          foreach ($data[$field] as $key => $value) {
            $clean_key = sanitize_key($key);
            if (is_string($value)) {
              $sanitized[$field][$clean_key] = sanitize_text_field($value);
            } elseif (is_numeric($value)) {
              $sanitized[$field][$clean_key] = is_float($value) ? floatval($value) : intval($value);
            } elseif (is_bool($value)) {
              $sanitized[$field][$clean_key] = $value;
            }
          }
        }
      }

      // Store any remaining data as raw (limited to prevent abuse)
      $remaining_data = array_diff_key($data, $sanitized);
      if (!empty($remaining_data)) {
        $sanitized['additional_data'] = array_slice($remaining_data, 0, 10); // Limit to 10 extra fields
        foreach ($sanitized['additional_data'] as $key => $value) {
          if (is_string($value)) {
            $sanitized['additional_data'][$key] = sanitize_text_field($value);
          }
        }
      }
    }

    return $sanitized;
  }
}
