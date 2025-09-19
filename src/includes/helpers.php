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
      if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
        wpmps_log('DEBUG', wpmps_collect_context('token_origin', ['origin'=>'constant']));
      }
      return MP_ACCESS_TOKEN;
    }
    $opt = get_option('wpmps_access_token');
    if (is_string($opt) && $opt !== '') {
      if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
        wpmps_log('DEBUG', wpmps_collect_context('token_origin', ['origin'=>'option']));
      }
      return $opt;
    }
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('ERROR', wpmps_collect_context('token_origin', ['origin'=>'none']));
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
