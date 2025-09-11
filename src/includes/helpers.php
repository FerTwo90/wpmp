<?php
if (!defined('ABSPATH')) exit;

function wpmps_log($msg, $data = null){
  if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
  $line = '[WPMPS] '.$msg.' '.($data ? wp_json_encode($data) : '');
  error_log($line);
}

if (!function_exists('wpmps_get_access_token')) {
  function wpmps_get_access_token(){
    if (defined('MP_ACCESS_TOKEN') && !empty(MP_ACCESS_TOKEN)) {
      return MP_ACCESS_TOKEN;
    }
    $opt = get_option('wpmps_access_token');
    if (is_string($opt) && $opt !== '') return $opt;
    return '';
  }
}
