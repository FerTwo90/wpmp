<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Logger {
  const OPTION = 'wpmps_log_ring';
  const MAX_EVENTS = 500;

  public static function add($event){
    if (!is_array($event)) $event = ['message'=>(string)$event];
    // Ensure timestamp/channel
    if (empty($event['ts'])) $event['ts'] = gmdate('c');
    if (empty($event['channel'])) $event['channel'] = 'INFO';

    // Remove secrets if accidentally present
    foreach (['access_token','token','Authorization','authorization'] as $k){
      if (isset($event[$k])) unset($event[$k]);
    }

    // Append to ring buffer
    $ring = get_option(self::OPTION, []);
    if (!is_array($ring)) $ring = [];
    $ring[] = $event;
    if (count($ring) > self::MAX_EVENTS){
      $ring = array_slice($ring, -self::MAX_EVENTS);
    }
    update_option(self::OPTION, $ring, false);

    // Also forward to error_log if debug logging is on
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG){
      $line = '[WPMPS]['.$event['channel'].'] '.wp_json_encode($event);
      error_log($line);
    }
  }

  public static function all(){
    $ring = get_option(self::OPTION, []);
    return is_array($ring) ? $ring : [];
  }

  public static function clear(){
    update_option(self::OPTION, [], false);
  }

  public static function filtered($args = []){
    $items = self::all();
    $channel = isset($args['channel']) ? strtoupper(sanitize_text_field($args['channel'])) : '';
    $email   = isset($args['email']) ? sanitize_email($args['email']) : '';
    if ($channel){
      $items = array_values(array_filter($items, function($e) use ($channel){
        return isset($e['channel']) && strtoupper($e['channel']) === $channel;
      }));
    }
    if ($email){
      $items = array_values(array_filter($items, function($e) use ($email){
        return !empty($e['user_email']) && stripos($e['user_email'], $email) !== false;
      }));
    }
    // Latest first for display
    return array_reverse($items);
  }

  public static function download_ndjson(){
    $items = self::all();
    nocache_headers();
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="wpmps-logs-'.gmdate('Ymd-His').'.ndjson"');
    $out = fopen('php://output', 'w');
    foreach ($items as $e){
      fwrite($out, wp_json_encode($e)."\n");
    }
    fclose($out);
    exit;
  }
}

