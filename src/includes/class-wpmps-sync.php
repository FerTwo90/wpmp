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
    // Intentar buscar planes por endpoint oficial de preapproval plan (si existe)
    $resp = $client->search_preapproval_plans();
    if ($resp['http'] === 200 && !empty($resp['body']['results'])){
      foreach ($resp['body']['results'] as $it){
        $plans[] = self::normalize_plan($it);
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
}

