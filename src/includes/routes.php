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
  // Log headers/body summary on entry
  $h = [
    'ua' => $req->get_header('user_agent'),
    'xff'=> $req->get_header('x_forwarded_for'),
    'cfip'=> $req->get_header('cf_connecting_ip'),
    'rip'=> $req->get_header('x_real_ip'),
    'ct' => $req->get_header('content_type'),
    'sig_present' => $req->get_header('x_signature') ? true : false,
  ];
  $raw = $req->get_body();
  $raw_len = is_string($raw) ? strlen($raw) : 0;
  wpmps_log('WEBHOOK', wpmps_collect_context('webhook_received', [ 'headers'=>$h, 'raw_len'=>$raw_len ]));
  $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
  if (empty($token)) {
    return new WP_REST_Response(['ok'=>false,'err'=>'no token'], 400);
  }

  $query_id = sanitize_text_field($req->get_param('id'));
  $body     = $req->get_json_params();
  $body_id  = isset($body['data']['id']) ? sanitize_text_field($body['data']['id']) : '';

  $preapproval_id = $query_id ?: $body_id;
  if (!$preapproval_id) {
    wpmps_log('WEBHOOK', wpmps_collect_context('webhook', ['http_code'=>400,'reason'=>'no_id','body_has'=> array_keys((array)$body)]));
    return new WP_REST_Response(['ok'=>false,'err'=>'no id'], 400);
  }

  $client = new WPMPS_MP_Client($token);
  $resp   = $client->get_preapproval($preapproval_id);

  if ($resp['http'] !== 200) {
    $log_extra = [
      'http_code'      => $resp['http'] ?? 0,
      'preapproval_id' => $preapproval_id,
    ];

    $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];

    if (!empty($body['error'])) {
      $log_extra['mp_error'] = sanitize_text_field($body['error']);
    }

    if (!empty($body['message'])) {
      $log_extra['mp_message'] = sanitize_text_field($body['message']);
    }

    if (!empty($body['error_description'])) {
      $log_extra['mp_error_desc'] = sanitize_text_field($body['error_description']);
    }

    if (!empty($body['cause'])) {
      $cause = $body['cause'];
      if (is_array($cause)) {
        $first = isset($cause[0]) ? $cause[0] : $cause;
        if (is_array($first)) {
          if (!empty($first['code'])) {
            $log_extra['mp_cause_code'] = sanitize_text_field($first['code']);
          }
          if (!empty($first['description'])) {
            $log_extra['mp_cause_desc'] = sanitize_text_field($first['description']);
          }
        } elseif (is_scalar($first)) {
          $log_extra['mp_cause_desc'] = sanitize_text_field((string) $first);
        }
      } elseif (is_scalar($cause)) {
        $log_extra['mp_cause_desc'] = sanitize_text_field((string) $cause);
      }
    }

    $request_id = '';
    if (!empty($resp['request_id'])) {
      $request_id = $resp['request_id'];
    } elseif (!empty($body['request_id'])) {
      $request_id = $body['request_id'];
    }
    if ($request_id !== '') {
      $log_extra['request_id'] = sanitize_text_field($request_id);
    }

    if (!empty($resp['raw_body'])) {
      $raw_preview = $resp['raw_body'];
      if (!is_string($raw_preview)) {
        $raw_preview = wp_json_encode($raw_preview);
      }
      if (is_string($raw_preview) && $raw_preview !== '') {
        $preview = substr($raw_preview, 0, 500);
        $log_extra['body_raw_preview'] = sanitize_textarea_field($preview);
      }
    }

    wpmps_log('WEBHOOK', wpmps_collect_context('webhook_fetch', $log_extra));
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

      // Opcional: asignar/quitar rol configurado en ajustes
      $role_option = get_option('wpmps_role_on_authorized', '');
      if ($role_option === 1 || $role_option === '1') {
        $role_option = 'suscriptor_premium';
      }
      if (is_string($role_option)) {
        $role_option = trim($role_option);
      } else {
        $role_option = '';
      }
      if ($role_option !== '') {
        if (!get_role($role_option) && $role_option === 'suscriptor_premium') {
          add_role($role_option, __('Suscriptor Premium','wp-mp-subscriptions'), ['read'=>true]);
        }
        $u = new WP_User($user->ID);
        if ($active === 'yes') {
          $u->add_role($role_option);
        } else {
          $u->remove_role($role_option);
        }
      }
    }
  }

  wpmps_log('WEBHOOK', wpmps_collect_context('webhook', [
    'http_code' => 200,
    'preapproval_id' => $preId,
    'status'=>$status,
    'user_email'=>$email,
  ]));
  return new WP_REST_Response(['ok'=>true], 200);
}

add_action('template_redirect', function(){
  $target = 'finalizar-subscripcion';
  $request_uri = $_SERVER['REQUEST_URI'] ?? '';
  $path = trim((string) parse_url($request_uri, PHP_URL_PATH), '/');
  $current_user = wp_get_current_user();
  
  // Solo registrar si coincide con la ruta objetivo
  if ($path === trim($target, '/')) {
    // Datos básicos para el resumen
    $log_data = [
      'channel' => 'SUBSCRIPTION',
      'ctx' => 'redirect',
      'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
      'path' => $path,
      'uri' => $request_uri,
      'full_url' => home_url($request_uri),
      'http_referer' => $_SERVER['HTTP_REFERER'] ?? '',
      'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
      'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    
    // Asegurar que los datos importantes estén en el nivel superior para el resumen
    if (isset($_GET['preapproval_id'])) {
      $log_data['preapproval_id'] = sanitize_text_field(wp_unslash($_GET['preapproval_id']));
    }
    if (isset($_GET['id'])) {
      $log_data['mp_id'] = sanitize_text_field(wp_unslash($_GET['id']));
    }
    
    wpmps_log('SUBSCRIPTION', $log_data);
  }

  if ($path !== trim($target, '/')) {
    return;
  }

  $destination = home_url('/' . trim($target, '/'));

  if (!is_user_logged_in()) {
    wp_redirect(wp_login_url($destination));
    exit;
  }

  $preapproval_id = '';
  if (isset($_GET['preapproval_id'])) {
    $preapproval_id = sanitize_text_field(wp_unslash($_GET['preapproval_id']));
  } elseif (isset($_GET['id'])) {
    $preapproval_id = sanitize_text_field(wp_unslash($_GET['id']));
  } else {
    $first_key = array_key_first($_GET ?? []);
    if ($first_key && !in_array($first_key, ['ok','mp_err','mp_status'], true)) {
      $preapproval_id = sanitize_text_field(wp_unslash($_GET[$first_key]));
    }
  }

  if ($preapproval_id === '') {
    return;
  }

  $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
  if (empty($token)) {
    $error_message = __('Falta Access Token de Mercado Pago.', 'wp-mp-subscriptions');
    wp_redirect(add_query_arg('mp_err', rawurlencode($error_message), $destination));
    exit;
  }

  $client = new WPMPS_MP_Client($token);
  $response = $client->get_preapproval($preapproval_id);
  $http = $response['http'] ?? 0;
  
  if ($http !== 200 || empty($response['body'])) {
    $error_message = __('No se pudo consultar el estado de la suscripción.', 'wp-mp-subscriptions');
    $details = [];

    if (!empty($preapproval_id)) {
      $details[] = sprintf(__('ID: %s', 'wp-mp-subscriptions'), sanitize_text_field($preapproval_id));
    }

    if ($http || $http === 0) {
      $details[] = sprintf(__('HTTP %d', 'wp-mp-subscriptions'), intval($http));
    }

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    if (!empty($body['error'])) {
      $details[] = sanitize_text_field($body['error']);
    }
    if (!empty($body['message'])) {
      $details[] = sanitize_text_field($body['message']);
    }
    if (!empty($body['error_description'])) {
      $details[] = sanitize_text_field($body['error_description']);
    }
    if (!empty($body['cause'])) {
      $cause = $body['cause'];
      if (is_array($cause)) {
        $first = isset($cause[0]) ? $cause[0] : $cause;
        if (is_array($first)) {
          if (!empty($first['code'])) {
            $details[] = sprintf(__('Causa %s', 'wp-mp-subscriptions'), sanitize_text_field($first['code']));
          }
          if (!empty($first['description'])) {
            $details[] = sanitize_text_field($first['description']);
          }
        } elseif (is_scalar($first)) {
          $details[] = sanitize_text_field((string) $first);
        }
      } elseif (is_scalar($cause)) {
        $details[] = sanitize_text_field((string) $cause);
      }
    }

    if (!empty($response['request_id'])) {
      $details[] = sprintf(__('Request ID: %s', 'wp-mp-subscriptions'), sanitize_text_field($response['request_id']));
    }

    if (!empty($response['raw_body'])) {
      $raw_preview = $response['raw_body'];
      if (!is_string($raw_preview)) {
        $raw_preview = wp_json_encode($raw_preview);
      }
      if (is_string($raw_preview) && $raw_preview !== '') {
        $details[] = sanitize_textarea_field(substr($raw_preview, 0, 200));
      }
    }

    if (!empty($details)) {
      $error_message .= ' '.implode(' | ', array_unique(array_filter($details)));
    }

    $error_message = sanitize_textarea_field($error_message);

    wp_redirect(add_query_arg('mp_err', rawurlencode($error_message), $destination));
    exit;
  }

  $body = is_array($response['body']) ? $response['body'] : [];
  $status = sanitize_text_field($body['status'] ?? 'pending');
  $remote_id = sanitize_text_field($body['id'] ?? $preapproval_id);
  $user_id = get_current_user_id();

  update_user_meta($user_id, '_mp_preapproval_id', $remote_id);
  update_user_meta($user_id, '_mp_sub_status', $status);

  $redirect_url = add_query_arg(['ok' => 1, 'mp_status' => $status], $destination);
  wp_redirect($redirect_url);
  exit;
});

add_action('wp_footer', function(){
  $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
  $path = trim((string) parse_url($request_uri, PHP_URL_PATH), '/');
  if ($path !== 'finalizar-subscripcion') {
    return;
  }

  if (isset($_GET['ok'])) {
    $status = isset($_GET['mp_status']) ? sanitize_text_field(wp_unslash($_GET['mp_status'])) : 'pending';
    $message = sprintf(__('Suscripción registrada. Estado: %s', 'wp-mp-subscriptions'), $status);
  } elseif (isset($_GET['mp_err'])) {
    $message = sanitize_text_field(wp_unslash($_GET['mp_err']));
  } else {
    return;
  }

  echo '<script>try{alert('.wp_json_encode($message).');}catch(e){console.log('.wp_json_encode($message).');}</script>';
});
