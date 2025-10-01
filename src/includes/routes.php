<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('mp/v1', '/webhook', [
    'methods'  => 'POST',
    'callback' => 'wpmps_handle_webhook',
    'permission_callback' => '__return_true'
  ]);
  
  // Test endpoint to verify webhook is reachable
  register_rest_route('mp/v1', '/webhook-test', [
    'methods'  => ['GET', 'POST'],
    'callback' => 'wpmps_test_webhook',
    'permission_callback' => '__return_true'
  ]);
});

function wpmps_handle_webhook(WP_REST_Request $req){
  // IMMEDIATE logging to confirm webhook is being called
  wpmps_log_webhook('function_called', [
    'timestamp' => current_time('mysql'),
    'method' => $req->get_method(),
    'immediate_test' => 'webhook_function_executed'
  ]);
  
  // Capture all headers for debugging
  $all_headers = $req->get_headers();
  $h = [
    'ua' => $req->get_header('user_agent'),
    'xff'=> $req->get_header('x_forwarded_for'),
    'cfip'=> $req->get_header('cf_connecting_ip'),
    'rip'=> $req->get_header('x_real_ip'),
    'ct' => $req->get_header('content_type'),
    'sig_present' => $req->get_header('x_signature') ? true : false,
    'x_signature' => $req->get_header('x_signature') ? substr($req->get_header('x_signature'), 0, 20) . '...' : null,
  ];
  
  // Get raw body and try different parsing methods
  $raw = $req->get_body();
  $raw_len = is_string($raw) ? strlen($raw) : 0;
  $raw_preview = is_string($raw) ? substr($raw, 0, 500) : '';
  
  // Try different ways to get the data
  $query_params = $req->get_query_params();
  $json_params = $req->get_json_params();
  $body_params = $req->get_body_params();
  $all_params = $req->get_params();
  
  // Log comprehensive request info
  wpmps_log_webhook('received', [
    'headers' => $h,
    'all_headers_count' => count($all_headers),
    'raw_len' => $raw_len,
    'raw_preview' => $raw_preview,
    'query_params' => $query_params,
    'json_params' => $json_params,
    'body_params' => $body_params,
    'all_params_keys' => array_keys($all_params),
    'method' => $req->get_method(),
    'content_type' => $req->get_content_type(),
  ]);
  
  $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
  if (empty($token)) {
    wpmps_log_error('webhook', 'no_token', 'Missing access token', []);
    return new WP_REST_Response(['ok'=>false,'err'=>'no token'], 400);
  }

  // Try multiple ways to extract the preapproval ID
  $query_id = sanitize_text_field($req->get_param('id'));
  $body = $req->get_json_params();
  $body_id = '';
  
  // Check different possible locations for the ID
  if (isset($body['data']['id'])) {
    $body_id = sanitize_text_field($body['data']['id']);
  } elseif (isset($body['id'])) {
    $body_id = sanitize_text_field($body['id']);
  } elseif (isset($body['preapproval_id'])) {
    $body_id = sanitize_text_field($body['preapproval_id']);
  }
  
  // Also check query parameters
  if (!$query_id && isset($query_params['preapproval_id'])) {
    $query_id = sanitize_text_field($query_params['preapproval_id']);
  }
  
  $preapproval_id = $query_id ?: $body_id;
  
  // Enhanced logging for missing ID
  if (!$preapproval_id) {
    $debug_info = [
      'query_id' => $query_id,
      'body_id' => $body_id,
      'body_keys' => array_keys((array)$body),
      'query_keys' => array_keys($query_params),
      'all_params_keys' => array_keys($all_params),
      'raw_body_sample' => $raw_preview,
    ];
    wpmps_log_error('webhook', 'no_preapproval_id', 'Webhook missing preapproval ID', $debug_info);
    return new WP_REST_Response(['ok'=>false,'err'=>'no id'], 400);
  }
  
  // Log successful ID extraction
  wpmps_log_webhook('id_extracted', [
    'preapproval_id' => $preapproval_id,
    'source' => $query_id ? 'query' : 'body',
    'query_id' => $query_id,
    'body_id' => $body_id,
  ]);

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

    wpmps_log_error('webhook', 'fetch_failed', 'Failed to fetch preapproval data', $log_extra);
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

      if (function_exists('wpmps_sync_subscription_role')) {
        wpmps_sync_subscription_role($user->ID, $status);
      }
    }
  }

  wpmps_log_webhook('processed', [
    'preapproval_id' => $preId,
    'status' => $status,
    'user_email' => $email,
    'http_code' => 200
  ]);
  return new WP_REST_Response(['ok'=>true], 200);
}

function wpmps_test_webhook(WP_REST_Request $req){
  $method = $req->get_method();
  $timestamp = current_time('mysql');
  
  wpmps_log_webhook('test_endpoint_hit', [
    'method' => $method,
    'timestamp' => $timestamp,
    'params' => $req->get_params(),
    'headers' => $req->get_headers(),
    'body' => $req->get_body(),
    'test' => 'endpoint_working'
  ]);
  
  return new WP_REST_Response([
    'ok' => true,
    'message' => 'Test webhook endpoint working',
    'method' => $method,
    'timestamp' => $timestamp,
    'logged' => true
  ], 200);
}

add_action('template_redirect', function(){
  $target = 'finalizar-suscripcion';
  $request_uri = $_SERVER['REQUEST_URI'] ?? '';
  $path = trim((string) parse_url($request_uri, PHP_URL_PATH), '/');
  $current_user = wp_get_current_user();
  $sanitized_query_args = [];
  // Check if this is the finalization page
  if ($path !== trim($target, '/')) {
    return; // Not our target page
  }
  
  // Sanitize query args for logging
  if (!empty($_GET)) {
    foreach ($_GET as $q_key => $q_value) {
      $safe_key = sanitize_key($q_key);
      if ($safe_key === '') continue;
      $unslashed = wp_unslash($q_value);
      if (is_array($unslashed)) {
        $sanitized_query_args[$safe_key] = map_deep($unslashed, 'sanitize_text_field');
      } else {
        $sanitized_query_args[$safe_key] = sanitize_text_field($unslashed);
      }
    }
  }
  
  wpmps_log_subscription('finalize_page_accessed', [
    'preapproval_id' => $_GET['preapproval_id'] ?? $_GET['id'] ?? '',
    'query_args' => $sanitized_query_args
  ]);

  $destination = home_url('/' . trim($target, '/'));

  if (!is_user_logged_in()) {
    wpmps_log_auth('required_for_finalization', [
      'destination' => $destination,
    ]);
    wp_redirect(wp_login_url($destination));
    exit;
  }

  $preapproval_id = '';
  $preapproval_source = '';
  if (isset($_GET['preapproval_id'])) {
    $preapproval_id = sanitize_text_field(wp_unslash($_GET['preapproval_id']));
    $preapproval_source = 'preapproval_id';
  } elseif (isset($_GET['id'])) {
    $preapproval_id = sanitize_text_field(wp_unslash($_GET['id']));
    $preapproval_source = 'id';
  } else {
    $first_key = array_key_first($_GET ?? []);
    if ($first_key && !in_array($first_key, ['ok','mp_err','mp_status'], true)) {
      $preapproval_id = sanitize_text_field(wp_unslash($_GET[$first_key]));
      $preapproval_source = sanitize_key($first_key);
    }
  }

  $success_destination = apply_filters('wpmps_finalization_redirect', home_url('/inicio/'));

  if ($preapproval_id === '') {
    $status_hint = '';
    if (isset($_GET['mp_status'])) {
      $status_hint = sanitize_text_field(wp_unslash($_GET['mp_status']));
    }

    if ($status_hint !== '') {
      $user_id = get_current_user_id();
      update_user_meta($user_id, '_mp_sub_status', $status_hint);
      if (function_exists('wpmps_sync_subscription_role')) {
        wpmps_sync_subscription_role($user_id, $status_hint);
      }
      $redirect_url = add_query_arg(['ok' => 1, 'mp_status' => $status_hint], $success_destination);
      wpmps_log_subscription('synced_from_query', [
        'status' => $status_hint,
        'query_args' => $sanitized_query_args,
        'redirect_url' => $redirect_url,
      ]);
      wp_redirect($redirect_url);
      exit;
    } else {
      wpmps_log_error('finalization', 'missing_preapproval_id', 'No preapproval ID found in query parameters', [
        'query_args' => $sanitized_query_args,
      ]);
    }
    return;
  }

  wpmps_log_subscription('preapproval_detected', [
    'preapproval_id' => $preapproval_id,
    'preapproval_source' => $preapproval_source,
    'query_args' => $sanitized_query_args,
  ]);

  $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
  if (empty($token)) {
    $error_message = __('Falta Access Token de Mercado Pago.', 'wp-mp-subscriptions');
    wpmps_log_error('finalization', 'no_access_token', 'Missing Mercado Pago access token', [
      'preapproval_id' => $preapproval_id,
    ]);
    wp_redirect(add_query_arg('mp_err', rawurlencode($error_message), $destination));
    exit;
  }

  $client = new WPMPS_MP_Client($token);
  $token_hash = substr(md5($token), 0, 12);
  wpmps_log_subscription('fetch_attempt', [
    'preapproval_id' => $preapproval_id,
    'token_hash' => $token_hash,
  ]);
  $response = $client->get_preapproval($preapproval_id);
  $http = $response['http'] ?? 0;
  
  if ($http !== 200 || empty($response['body'])) {
    $failure_log = [
      'preapproval_id' => $preapproval_id,
      'http_code' => intval($http),
      'token_hash' => $token_hash,
    ];

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
      $failure_log['mp_error'] = sanitize_text_field($body['error']);
    }
    if (!empty($body['message'])) {
      $details[] = sanitize_text_field($body['message']);
      $failure_log['mp_message'] = sanitize_text_field($body['message']);
    }
    if (!empty($body['error_description'])) {
      $details[] = sanitize_text_field($body['error_description']);
      $failure_log['mp_error_desc'] = sanitize_text_field($body['error_description']);
    }
    if (!empty($body['cause'])) {
      $cause = $body['cause'];
      if (is_array($cause)) {
        $first = isset($cause[0]) ? $cause[0] : $cause;
        if (is_array($first)) {
          if (!empty($first['code'])) {
            $details[] = sprintf(__('Causa %s', 'wp-mp-subscriptions'), sanitize_text_field($first['code']));
            $failure_log['mp_cause_code'] = sanitize_text_field($first['code']);
          }
          if (!empty($first['description'])) {
            $details[] = sanitize_text_field($first['description']);
            $failure_log['mp_cause_desc'] = sanitize_text_field($first['description']);
          }
        } elseif (is_scalar($first)) {
          $details[] = sanitize_text_field((string) $first);
          $failure_log['mp_cause_desc'] = sanitize_text_field((string) $first);
        }
      } elseif (is_scalar($cause)) {
        $details[] = sanitize_text_field((string) $cause);
        $failure_log['mp_cause_desc'] = sanitize_text_field((string) $cause);
      }
    }

    if (!empty($response['request_id'])) {
      $details[] = sprintf(__('Request ID: %s', 'wp-mp-subscriptions'), sanitize_text_field($response['request_id']));
      $failure_log['request_id'] = sanitize_text_field($response['request_id']);
    }

    if (!empty($response['raw_body'])) {
      $raw_preview = $response['raw_body'];
      if (!is_string($raw_preview)) {
        $raw_preview = wp_json_encode($raw_preview);
      }
      if (is_string($raw_preview) && $raw_preview !== '') {
        $details[] = sanitize_textarea_field(substr($raw_preview, 0, 200));
        $failure_log['body_raw_preview'] = sanitize_textarea_field(substr($raw_preview, 0, 200));
      }
    }

    if (!empty($details)) {
      $error_message .= ' '.implode(' | ', array_unique(array_filter($details)));
    }

    $error_message = sanitize_textarea_field($error_message);

    wpmps_log_error('finalization', 'fetch_failed', 'Failed to fetch preapproval status from MP', $failure_log);

    wp_redirect(add_query_arg('mp_err', rawurlencode($error_message), $destination));
    exit;
  }

  $body = is_array($response['body']) ? $response['body'] : [];
  $status = sanitize_text_field($body['status'] ?? 'pending');
  $remote_id = sanitize_text_field($body['id'] ?? $preapproval_id);
  $user_id = get_current_user_id();

  wpmps_log_subscription('fetch_success', [
    'preapproval_id' => $remote_id,
    'status' => $status,
    'http_code' => intval($http),
    'token_hash' => $token_hash,
  ]);

  update_user_meta($user_id, '_mp_preapproval_id', $remote_id);
  update_user_meta($user_id, '_mp_sub_status', $status);
  if (function_exists('wpmps_sync_subscription_role')) {
    wpmps_sync_subscription_role($user_id, $status);
  }

  $redirect_url = add_query_arg(['ok' => 1, 'mp_status' => $status], $success_destination);
  wpmps_log_subscription('finalize_complete', [
    'preapproval_id' => $remote_id,
    'status' => $status,
    'redirect_url' => $redirect_url,
    'token_hash' => $token_hash,
  ]);
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
    echo '<script>try{alert('.wp_json_encode($message).');}catch(e){console.log('.wp_json_encode($message).');}</script>';
  } elseif (isset($_GET['mp_err'])) {
    $message = sanitize_text_field(wp_unslash($_GET['mp_err']));
    echo '<script>try{alert('.wp_json_encode($message).');}catch(e){console.error('.wp_json_encode($message).');}</script>';
  }
});
