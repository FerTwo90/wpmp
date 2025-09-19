<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Sync {
  public static function get_plans($force = false){
    $cache_key = 'wpmps_plans_cache';
    if (!$force){
      $cached = get_transient($cache_key);
      if ($cached !== false) return $cached;
    }
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if (empty($token)) return [];
    $client = new WPMPS_MP_Client($token);
    $plans = [];
    // Log intento de sincronización
    self::log_event(['type'=>'sync-plans-start']);
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('sync_plans_start', ['force'=>$force?1:0]));
    }
    // Intentar buscar planes por endpoint oficial de preapproval plan (si existe)
    $resp = $client->search_preapproval_plans(['limit'=>50]);
    if ($resp['http'] === 200 && !empty($resp['body']['results'])){
      foreach ($resp['body']['results'] as $it){
        $plans[] = self::normalize_plan($it);
      }
      self::log_event(['type'=>'sync-plans-ok','count'=>count($plans)]);
      if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
        wpmps_log('DEBUG', wpmps_collect_context('sync_plans_ok', ['count'=>count($plans)]));
      }
    } else {
      // Sin fallback por plan preferido: se reporta error
      self::log_event(['type'=>'sync-plans-error','http'=>$resp['http'],'detail'=>$resp['body']]);
      if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
        wpmps_log('ERROR', wpmps_collect_context('sync_plans_error', ['http'=>$resp['http'] ?? 0]));
      }
    }
    set_transient($cache_key, $plans, 20 * MINUTE_IN_SECONDS);
    return $plans;
  }

  public static function clear_cache(){ delete_transient('wpmps_plans_cache'); }

  private static function normalize_plan($p){
    return [
      'id'        => $p['id'] ?? ($p['preapproval_plan_id'] ?? ''),
      'name'      => $p['reason'] ?? ($p['name'] ?? ''),
      'amount'    => isset($p['auto_recurring']['transaction_amount']) ? $p['auto_recurring']['transaction_amount'] : ($p['amount'] ?? ''),
      'frequency' => isset($p['auto_recurring']) ? ($p['auto_recurring']['frequency'].'/'.$p['auto_recurring']['frequency_type']) : ($p['frequency'] ?? ''),
      'status'    => $p['status'] ?? '',
    ];
  }

  private static function log_event($data){
    $events = get_option('wpmps_webhook_events', []);
    if (!is_array($events)) $events = [];
    $data['date'] = current_time('mysql');
    $events[] = $data;
    if (count($events) > 50) $events = array_slice($events, -50);
    update_option('wpmps_webhook_events', $events, false);
  }
}
