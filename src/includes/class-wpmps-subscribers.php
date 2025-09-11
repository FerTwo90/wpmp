<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Subscribers {
  public static function get_subscribers(){
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
    $rows = [];
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
    return true;
  }
}

