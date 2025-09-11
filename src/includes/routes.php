<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('mp/v1', '/webhook', [
    'methods'  => 'POST',
    'callback' => 'wpmps_handle_webhook',
    'permission_callback' => '__return_true'
  ]);
});

function wpmps_handle_webhook(WP_REST_Request $req){
  $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
  if (empty($token)) {
    return new WP_REST_Response(['ok'=>false,'err'=>'no token'], 400);
  }

  $query_id = sanitize_text_field($req->get_param('id'));
  $body     = $req->get_json_params();
  $body_id  = isset($body['data']['id']) ? sanitize_text_field($body['data']['id']) : '';

  $preapproval_id = $query_id ?: $body_id;
  if (!$preapproval_id) {
    wpmps_log('Webhook sin id', $body);
    return new WP_REST_Response(['ok'=>false,'err'=>'no id'], 400);
  }

  $client = new WPMPS_MP_Client($token);
  $resp   = $client->get_preapproval($preapproval_id);

  if ($resp['http'] !== 200) {
    wpmps_log('No se pudo consultar preapproval', $resp);
    return new WP_REST_Response(['ok'=>false,'err'=>'fetch'], 200);
  }

  $pre = $resp['body'];
  $email  = sanitize_email($pre['payer_email'] ?? '');
  $status = sanitize_text_field($pre['status'] ?? '');
  $planId = sanitize_text_field($pre['preapproval_plan_id'] ?? '');
  $preId  = sanitize_text_field($pre['id'] ?? $preapproval_id);

  if ($email) {
    $user = get_user_by('email', $email);
    if ($user) {
      $active = ($status === 'authorized') ? 'yes' : 'no';
      update_user_meta($user->ID, '_suscripcion_activa', $active);
      if ($preId) update_user_meta($user->ID, '_mp_preapproval_id', $preId);
      if ($planId) update_user_meta($user->ID, '_mp_plan_id', $planId);
      update_user_meta($user->ID, '_mp_updated_at', current_time('mysql'));

      // Opcional: asignar/quitar rol suscriptor_premium
      $map_role = (bool) get_option('wpmps_role_on_authorized', false);
      if ($map_role) {
        $role = 'suscriptor_premium';
        if (!get_role($role)) add_role($role, __('Suscriptor Premium','wp-mp-subscriptions'), ['read'=>true]);
        $u = new WP_User($user->ID);
        if ($active === 'yes') { $u->add_role($role); } else { $u->remove_role($role); }
      }
    }
  }

  // Persistir un pequeño log de eventos (máx. 50)
  $events = get_option('wpmps_webhook_events', []);
  if (!is_array($events)) $events = [];
  $events[] = ['date'=>current_time('mysql'),'preapproval_id'=>$preId,'status'=>$status,'email'=>$email];
  if (count($events) > 50) $events = array_slice($events, -50);
  update_option('wpmps_webhook_events', $events, false);

  wpmps_log('Webhook OK', ['id'=>$preId,'status'=>$status,'email'=>$email]);
  return new WP_REST_Response(['ok'=>true], 200);
}
