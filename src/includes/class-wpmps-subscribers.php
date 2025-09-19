<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Subscribers {
  public static function get_subscribers(){
    $rows = [];
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
    if ($token){
      // Prefer live data from MP
      try {
        $client = new WPMPS_MP_Client($token);
        $limit = 50; $offset = 0; $fetched = 0; $max = 200;
        do {
          $resp = $client->search_preapprovals(['limit'=>$limit,'offset'=>$offset]);
          if (($resp['http'] ?? 0) !== 200) break;
          $list = [];
          if (isset($resp['body']['results']) && is_array($resp['body']['results'])) $list = $resp['body']['results'];
          elseif (is_array($resp['body'])) $list = $resp['body'];
          foreach ($list as $item){
            $email = sanitize_email($item['payer_email'] ?? '');
            $user  = $email ? get_user_by('email', $email) : null;
            $auto  = is_array($item['auto_recurring'] ?? null) ? $item['auto_recurring'] : [];
            $rows[] = [
              'user_id'        => $user ? intval($user->ID) : 0,
              'email'          => $email,
              'preapproval_id' => sanitize_text_field($item['id'] ?? ''),
              'plan_id'        => sanitize_text_field($item['preapproval_plan_id'] ?? ''),
              'status'         => sanitize_text_field($item['status'] ?? ''),
              'reason'         => sanitize_text_field($item['reason'] ?? ''),
              'amount'         => isset($auto['transaction_amount']) ? floatval($auto['transaction_amount']) : '',
              'currency'       => sanitize_text_field($auto['currency_id'] ?? ''),
              'frequency'      => isset($auto['frequency']) ? intval($auto['frequency']) : '',
              'frequency_type' => sanitize_text_field($auto['frequency_type'] ?? ''),
              'date_created'   => sanitize_text_field($item['date_created'] ?? ''),
              'updated_at'     => sanitize_text_field($item['last_modified'] ?? ($item['date_created'] ?? '')),
              'init_point'     => esc_url_raw($item['init_point'] ?? ''),
              'back_url'       => esc_url_raw($item['back_url'] ?? ''),
            ];
          }
          $count = count($list);
          $fetched += $count;
          $offset += $limit;
        } while ($count === $limit && $fetched < $max);
      } catch (\Throwable $e) {
        if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
          wpmps_log('ERROR', wpmps_collect_context('subs_list_error', ['message'=>$e->getMessage()]));
        }
      }
    }

    if (!empty($rows)) return $rows;

    // Fallback: reconstruct from WP user_meta if API unavailable
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
    foreach ($users as $u){
      $rows[] = [
        'user_id'       => $u->ID,
        'email'         => $u->user_email,
        'preapproval_id'=> get_user_meta($u->ID, '_mp_preapproval_id', true),
        'plan_id'       => get_user_meta($u->ID, '_mp_plan_id', true),
        'status'        => get_user_meta($u->ID, '_suscripcion_activa', true) === 'yes' ? 'authorized' : 'inactive',
        'updated_at'    => get_user_meta($u->ID, '_mp_updated_at', true),
      ];
    }
    return $rows;
  }

  public static function refresh_subscriber($user_id){
    $pre_id = get_user_meta($user_id, '_mp_preapproval_id', true);
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('refresh_subscriber_start', ['user_id'=>intval($user_id),'preapproval_id'=>$pre_id]));
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
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('refresh_subscriber_done', ['user_id'=>intval($user_id),'status'=>$status]));
    }
    return true;
  }
}
