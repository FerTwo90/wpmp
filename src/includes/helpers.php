<?php
if (!defined('ABSPATH')) exit;

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
      if (in_array($role_option, $roles_before, true)) {
        $wp_user->remove_role($role_option);
      }
      if (empty($wp_user->roles)) {
        $fallback = get_option('default_role', 'subscriber');
        if (!empty($fallback)) {
          $wp_user->set_role($fallback);
        }
      }
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
