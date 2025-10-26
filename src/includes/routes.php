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
  $webhook_api_start_time = microtime(true);
  $resp   = $client->get_preapproval($preapproval_id);
  $webhook_api_duration = round((microtime(true) - $webhook_api_start_time) * 1000, 2);

  // Log webhook API call (requirement 3.2)
  wpmps_log_webhook('api_call_completed', [
    'preapproval_id' => $preapproval_id,
    'http_code' => $resp['http'] ?? 0,
    'response_time_ms' => $webhook_api_duration,
    'response_size' => isset($resp['body']) ? strlen(json_encode($resp['body'])) : 0,
    'source' => 'webhook'
  ]);

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

  $webhook_processing_start_time = microtime(true);
  $user_found = false;
  $metadata_updated = false;
  $role_synced = false;
  
  if ($email) {
    $user = get_user_by('email', $email);
    if ($user) {
      $user_found = true;
      $active = ($status === 'authorized') ? 'yes' : 'no';
      
      // Update metadata
      update_user_meta($user->ID, '_suscripcion_activa', $active);
      if ($preId) update_user_meta($user->ID, '_mp_preapproval_id', $preId);
      if ($planId) update_user_meta($user->ID, '_mp_plan_id', $planId);
      update_user_meta($user->ID, '_mp_updated_at', current_time('mysql'));
      $metadata_updated = true;

      // Sync role
      if (function_exists('wpmps_sync_subscription_role')) {
        $role_synced = wpmps_sync_subscription_role($user->ID, $status);
      }
      
      // Log user association and update results (requirement 3.2)
      wpmps_log_webhook('user_association_success', [
        'preapproval_id' => $preId,
        'user_id' => $user->ID,
        'user_email' => $email,
        'status' => $status,
        'plan_id' => $planId,
        'metadata_updated' => $metadata_updated,
        'role_synced' => $role_synced,
        'source' => 'webhook'
      ]);
    } else {
      // Log failed user association (requirement 3.3)
      wpmps_log_webhook('user_association_failed', [
        'preapproval_id' => $preId,
        'user_email' => $email,
        'status' => $status,
        'plan_id' => $planId,
        'reason' => 'user_not_found_by_email',
        'source' => 'webhook'
      ]);
    }
  } else {
    // Log missing email (requirement 3.3)
    wpmps_log_webhook('user_association_failed', [
      'preapproval_id' => $preId,
      'status' => $status,
      'plan_id' => $planId,
      'reason' => 'no_email_in_preapproval',
      'source' => 'webhook'
    ]);
  }

  $webhook_processing_duration = round((microtime(true) - $webhook_processing_start_time) * 1000, 2);

  // Log final webhook processing results (requirement 3.4)
  wpmps_log_webhook('processed', [
    'preapproval_id' => $preId,
    'status' => $status,
    'user_email' => $email,
    'user_found' => $user_found,
    'metadata_updated' => $metadata_updated,
    'role_synced' => $role_synced,
    'http_code' => 200,
    'api_response_time_ms' => $webhook_api_duration,
    'processing_time_ms' => $webhook_processing_duration,
    'total_time_ms' => round((microtime(true) - $webhook_api_start_time) * 1000, 2)
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

// AJAX handler for postMessage preapproval capture
add_action('wp_ajax_wpmps_process_postmessage_capture', 'wpmps_handle_postmessage_capture');
add_action('wp_ajax_nopriv_wpmps_process_postmessage_capture', 'wpmps_handle_postmessage_capture');

// AJAX handler for modal action logging
add_action('wp_ajax_wpmps_log_modal_action', 'wpmps_handle_modal_action_log');
add_action('wp_ajax_nopriv_wpmps_log_modal_action', 'wpmps_handle_modal_action_log');

// AJAX handler for postMessage event logging (requirement 3.1)
add_action('wp_ajax_wpmps_log_postmessage_event', 'wpmps_handle_postmessage_event_log');
add_action('wp_ajax_nopriv_wpmps_log_postmessage_event', 'wpmps_handle_postmessage_event_log');

function wpmps_handle_postmessage_capture() {
  // Verify nonce for security
  if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wpmps_postmessage_nonce')) {
    $error_response = wpmps_create_error_response(
      'Token de seguridad inválido',
      'invalid_nonce'
    );
    wp_send_json_error(wpmps_ensure_modal_closure($error_response));
    return;
  }

  // Check if user is logged in
  if (!is_user_logged_in()) {
    $error_response = wpmps_create_error_response(
      'Debes estar logueado para procesar la suscripción',
      'user_not_logged_in'
    );
    wp_send_json_error(wpmps_ensure_modal_closure($error_response));
    return;
  }

  $preapproval_id = sanitize_text_field($_POST['preapproval_id'] ?? '');
  $origin = sanitize_text_field($_POST['origin'] ?? '');

  if (empty($preapproval_id)) {
    $error_response = wpmps_create_error_response(
      'ID de suscripción faltante',
      'missing_preapproval_id',
      ['origin' => $origin]
    );
    wp_send_json_error(wpmps_ensure_modal_closure($error_response));
    return;
  }

  // Validate origin
  if (!function_exists('wpmps_validate_postmessage_origin') || !wpmps_validate_postmessage_origin($origin)) {
    wpmps_log_error('postmessage', 'invalid_origin', 'Invalid postMessage origin', [
      'origin' => $origin,
      'preapproval_id' => $preapproval_id,
      'user_id' => get_current_user_id()
    ]);
    
    $error_response = wpmps_create_error_response(
      'Origen de mensaje inválido',
      'invalid_origin',
      ['origin' => $origin, 'preapproval_id' => $preapproval_id]
    );
    wp_send_json_error(wpmps_ensure_modal_closure($error_response));
    return;
  }

  // Log the postMessage capture attempt
  wpmps_log_subscription('postmessage_received', [
    'preapproval_id' => $preapproval_id,
    'origin' => $origin,
    'user_id' => get_current_user_id(),
    'user_email' => wp_get_current_user()->user_email
  ]);

  // Process the preapproval_id using existing logic
  $result = wpmps_process_preapproval_capture($preapproval_id, $origin, 'postmessage');

  if ($result['success']) {
    // Ensure modal closure is enforced even on success
    $success_data = wpmps_ensure_modal_closure($result['data']);
    wp_send_json_success($success_data);
  } else {
    // Ensure modal closure is enforced on error
    $error_data = wpmps_ensure_modal_closure($result['data']);
    wp_send_json_error($error_data);
  }
}

/**
 * Common function to process preapproval capture from different sources
 * 
 * @param string $preapproval_id The preapproval ID to process
 * @param string $origin The origin of the capture (URL or 'traditional')
 * @param string $source The source type ('postmessage' or 'traditional')
 * @return array Result array with success status and data
 */
function wpmps_process_preapproval_capture($preapproval_id, $origin = '', $source = 'traditional') {
  $user_id = get_current_user_id();
  
  if (!$user_id) {
    return [
      'success' => false,
      'data' => wpmps_create_error_response('Usuario no logueado', 'user_not_logged_in')
    ];
  }

  // Get access token
  $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
  if (empty($token)) {
    wpmps_log_error('preapproval_capture', 'no_access_token', 'Missing Mercado Pago access token', [
      'preapproval_id' => $preapproval_id,
      'source' => $source,
      'origin' => $origin
    ]);
    return [
      'success' => false,
      'data' => wpmps_create_error_response(
        'Token de acceso de Mercado Pago faltante',
        'missing_access_token',
        ['preapproval_id' => $preapproval_id, 'source' => $source]
      )
    ];
  }

  // Fetch preapproval data from MP API
  $client = new WPMPS_MP_Client($token);
  $token_hash = substr(md5($token), 0, 12);
  
  wpmps_log_subscription('preapproval_fetch_attempt', [
    'preapproval_id' => $preapproval_id,
    'source' => $source,
    'origin' => $origin,
    'token_hash' => $token_hash,
    'user_id' => $user_id
  ]);

  $api_start_time = microtime(true);
  $response = $client->get_preapproval($preapproval_id);
  $api_duration = round((microtime(true) - $api_start_time) * 1000, 2); // milliseconds
  $http = $response['http'] ?? 0;

  // Log API response details (requirement 3.2)
  wpmps_log_subscription('api_response_received', [
    'preapproval_id' => $preapproval_id,
    'http_code' => intval($http),
    'response_time_ms' => $api_duration,
    'source' => $source,
    'origin' => $origin,
    'token_hash' => $token_hash,
    'user_id' => $user_id,
    'response_size' => isset($response['body']) ? strlen(json_encode($response['body'])) : 0
  ]);
  
  if ($http !== 200 || empty($response['body'])) {
    $failure_log = [
      'preapproval_id' => $preapproval_id,
      'http_code' => intval($http),
      'token_hash' => $token_hash,
      'source' => $source,
      'origin' => $origin,
      'user_id' => $user_id
    ];

    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    if (!empty($body['error'])) {
      $failure_log['mp_error'] = sanitize_text_field($body['error']);
    }
    if (!empty($body['message'])) {
      $failure_log['mp_message'] = sanitize_text_field($body['message']);
    }

    // Enhanced error handling: Store preapproval_id for later sync even when API fails (requirement 6.1, 6.3)
    $fallback_result = wpmps_handle_api_failure_fallback($preapproval_id, $user_id, $http, $source, $origin, $failure_log);
    
    wpmps_log_error('preapproval_capture', 'fetch_failed', 'Failed to fetch preapproval from MP API', $failure_log);
    
    // Return graceful error response that still closes modal and provides user feedback
    return [
      'success' => false,
      'data' => wpmps_create_api_failure_response($preapproval_id, $http, $source, $fallback_result)
    ];
  }

  // Process successful response
  $body = is_array($response['body']) ? $response['body'] : [];
  $raw_status = sanitize_text_field($body['status'] ?? 'pending');
  $status = function_exists('wpmps_validate_preapproval_status') 
    ? wpmps_validate_preapproval_status($raw_status) 
    : $raw_status;
  $remote_id = sanitize_text_field($body['id'] ?? $preapproval_id);
  $payer_email = sanitize_email($body['payer_email'] ?? '');
  $plan_id = sanitize_text_field($body['preapproval_plan_id'] ?? '');

  wpmps_log_subscription('preapproval_fetch_success', [
    'preapproval_id' => $remote_id,
    'status' => $status,
    'payer_email' => $payer_email,
    'plan_id' => $plan_id,
    'http_code' => intval($http),
    'source' => $source,
    'origin' => $origin,
    'user_id' => $user_id
  ]);

  // Associate by email from preapproval data (requirement 1.2, 6.2)
  $target_user_id = $user_id; // Default to current user
  $association_start_time = microtime(true);
  
  if (!empty($payer_email)) {
    $user_by_email = get_user_by('email', $payer_email);
    if ($user_by_email) {
      $target_user_id = $user_by_email->ID;
      $association_duration = round((microtime(true) - $association_start_time) * 1000, 2);
      
      wpmps_log_subscription('user_association_by_email', [
        'preapproval_id' => $remote_id,
        'payer_email' => $payer_email,
        'current_user_id' => $user_id,
        'target_user_id' => $target_user_id,
        'association_method' => 'email_match',
        'association_time_ms' => $association_duration,
        'status' => $status,
        'source' => $source,
        'origin' => $origin
      ]);
    } else {
      $association_duration = round((microtime(true) - $association_start_time) * 1000, 2);
      
      wpmps_log_subscription('user_association_fallback', [
        'preapproval_id' => $remote_id,
        'payer_email' => $payer_email,
        'current_user_id' => $user_id,
        'target_user_id' => $target_user_id,
        'association_method' => 'current_user_fallback',
        'reason' => 'email_not_found',
        'association_time_ms' => $association_duration,
        'status' => $status,
        'source' => $source,
        'origin' => $origin
      ]);
    }
  } else {
    $association_duration = round((microtime(true) - $association_start_time) * 1000, 2);
    
    wpmps_log_subscription('user_association_no_email', [
      'preapproval_id' => $remote_id,
      'current_user_id' => $user_id,
      'target_user_id' => $target_user_id,
      'association_method' => 'current_user_no_email',
      'association_time_ms' => $association_duration,
      'status' => $status,
      'source' => $source,
      'origin' => $origin
    ]);
  }

  // Update user metadata using the target user (either by email or current user)
  $metadata_start_time = microtime(true);
  $active = ($status === 'authorized') ? 'yes' : 'no';
  
  $metadata_updates = [
    '_suscripcion_activa' => $active,
    '_mp_preapproval_id' => $remote_id,
    '_mp_sub_status' => $status,
    '_mp_updated_at' => current_time('mysql')
  ];
  
  if ($plan_id) {
    $metadata_updates['_mp_plan_id'] = $plan_id;
  }
  
  // Apply metadata updates
  foreach ($metadata_updates as $meta_key => $meta_value) {
    update_user_meta($target_user_id, $meta_key, $meta_value);
  }
  
  $metadata_duration = round((microtime(true) - $metadata_start_time) * 1000, 2);
  
  // Log metadata update results (requirement 3.2)
  wpmps_log_subscription('metadata_updated', [
    'preapproval_id' => $remote_id,
    'user_id' => $target_user_id,
    'payer_email' => $payer_email,
    'status' => $status,
    'active' => $active,
    'plan_id' => $plan_id,
    'metadata_count' => count($metadata_updates),
    'update_time_ms' => $metadata_duration,
    'source' => $source,
    'origin' => $origin
  ]);

  // Sync subscription role
  $role_sync_start_time = microtime(true);
  $role_sync_result = false;
  
  if (function_exists('wpmps_sync_subscription_role')) {
    $role_sync_result = wpmps_sync_subscription_role($target_user_id, $status);
  }
  
  $role_sync_duration = round((microtime(true) - $role_sync_start_time) * 1000, 2);
  
  // Log role sync results (requirement 3.2)
  wpmps_log_subscription('role_sync_completed', [
    'preapproval_id' => $remote_id,
    'user_id' => $target_user_id,
    'status' => $status,
    'role_sync_result' => $role_sync_result ? 'success' : 'failed',
    'role_sync_time_ms' => $role_sync_duration,
    'source' => $source,
    'origin' => $origin
  ]);

  // Determine action based on status using new logic (requirement 1.4, 1.5, 1.6, 5.4)
  $action_data = function_exists('wpmps_determine_postmessage_action') 
    ? wpmps_determine_postmessage_action($status, $remote_id, $target_user_id)
    : [
        'status' => $status,
        'modal_action' => 'close',
        'message' => $status === 'authorized' 
          ? 'Suscripción autorizada exitosamente' 
          : 'Te notificaremos por email cuando tu suscripción esté autorizada',
        'message_type' => $status === 'authorized' ? 'success' : 'info',
        'should_redirect' => $status === 'authorized',
        'redirect_url' => $status === 'authorized' 
          ? add_query_arg(['ok' => 1, 'mp_status' => $status], home_url('/cartelera/'))
          : null
      ];

  // Log successful completion with comprehensive information (requirement 3.4)
  $total_processing_time = round((microtime(true) - $api_start_time) * 1000, 2);
  
  wpmps_log_subscription('preapproval_capture_complete', [
    'preapproval_id' => $remote_id,
    'status' => $status,
    'source' => $source,
    'origin' => $origin,
    'current_user_id' => $user_id,
    'target_user_id' => $target_user_id,
    'payer_email' => $payer_email,
    'plan_id' => $plan_id,
    'total_processing_time_ms' => $total_processing_time,
    'api_response_time_ms' => $api_duration,
    'association_method' => !empty($payer_email) && get_user_by('email', $payer_email) ? 'email_match' : 'current_user',
    'metadata_updates_count' => count($metadata_updates ?? []),
    'role_sync_result' => $role_sync_result ? 'success' : 'failed',
    'should_redirect' => $action_data['should_redirect'] ?? false,
    'redirect_url' => $action_data['redirect_url'] ?? null,
    'message_type' => $action_data['message_type'] ?? 'info',
    'communication_method' => $action_data['communication_method'] ?? 'unknown'
  ]);

  return [
    'success' => true,
    'data' => array_merge($action_data, [
      'preapproval_id' => $remote_id,
      'target_user_id' => $target_user_id,
      'payer_email' => $payer_email
    ])
  ];
}

function wpmps_handle_modal_action_log() {
  // Verify nonce for security
  if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wpmps_postmessage_nonce')) {
    wp_send_json_error(['message' => 'Invalid security token']);
    return;
  }

  $modal_action = sanitize_text_field($_POST['modal_action'] ?? '');
  $context_json = sanitize_textarea_field($_POST['context'] ?? '{}');
  
  if (empty($modal_action)) {
    wp_send_json_error(['message' => 'Missing modal_action']);
    return;
  }

  // Parse context data
  $context = [];
  if (!empty($context_json)) {
    $parsed = json_decode($context_json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
      // Sanitize context data
      foreach ($parsed as $key => $value) {
        $clean_key = sanitize_key($key);
        if ($clean_key && is_scalar($value)) {
          $context[$clean_key] = sanitize_text_field($value);
        }
      }
    }
  }

  // Log the modal action
  if (function_exists('wpmps_log_modal_action')) {
    wpmps_log_modal_action($modal_action, $context);
  }

  wp_send_json_success(['logged' => true, 'action' => $modal_action]);
}

function wpmps_handle_postmessage_event_log() {
  // Verify nonce for security
  if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wpmps_postmessage_nonce')) {
    wp_send_json_error(['message' => 'Invalid security token']);
    return;
  }

  $event_action = sanitize_text_field($_POST['event_action'] ?? '');
  $event_data_json = sanitize_textarea_field($_POST['event_data'] ?? '{}');
  $timestamp = intval($_POST['timestamp'] ?? 0);
  
  if (empty($event_action)) {
    wp_send_json_error(['message' => 'Missing event_action']);
    return;
  }

  // Parse event data
  $event_data = [];
  if (!empty($event_data_json)) {
    $parsed = json_decode($event_data_json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
      // Sanitize event data (requirement 2.4)
      foreach ($parsed as $key => $value) {
        $clean_key = sanitize_key($key);
        if ($clean_key && is_scalar($value)) {
          $event_data[$clean_key] = sanitize_text_field($value);
        }
      }
    }
  }

  // Handle postMessage failure events (requirement 6.2)
  if (strpos($event_action, 'timeout') !== false || 
      strpos($event_action, 'failure') !== false || 
      strpos($event_action, 'checkout_closed_without_postmessage') !== false) {
    
    $failure_type = $event_action;
    $user_id = get_current_user_id();
    
    // Log the failure using dedicated function
    if (function_exists('wpmps_detect_postmessage_failure')) {
      wpmps_detect_postmessage_failure($failure_type, $event_data);
    }
    
    // Ensure traditional flow is prepared as fallback
    if (function_exists('wpmps_ensure_traditional_flow_works')) {
      $fallback_result = wpmps_ensure_traditional_flow_works($user_id, $failure_type);
      
      // Return fallback information to frontend
      wp_send_json_success([
        'logged' => true, 
        'action' => $event_action,
        'fallback_prepared' => true,
        'traditional_flow_ready' => $fallback_result['traditional_flow_ready'],
        'message' => __('El sistema continuará funcionando normalmente. Si completaste el pago, recibirás confirmación por email.', 'wp-mp-subscriptions')
      ]);
      return;
    }
  }

  // Add additional context
  $log_data = array_merge($event_data, [
    'frontend_timestamp' => $timestamp,
    'backend_timestamp' => current_time('mysql'),
    'user_id' => get_current_user_id(),
    'user_email' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 200) : '',
    'referer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : ''
  ]);

  // Log the postMessage event with BUTTON/CHECKOUT channel (requirement 3.1)
  wpmps_log('BUTTON/CHECKOUT', wpmps_collect_context('postmessage_' . $event_action, $log_data));

  wp_send_json_success(['logged' => true, 'action' => $event_action]);
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

  // Check if this is a postMessage capture flow
  $is_postmessage_capture = isset($_GET['pm_capture']) && $_GET['pm_capture'] === '1';
  
  wpmps_log_subscription('finalize_page_accessed', [
    'preapproval_id' => $_GET['preapproval_id'] ?? $_GET['id'] ?? '',
    'query_args' => $sanitized_query_args,
    'is_postmessage_capture' => $is_postmessage_capture
  ]);

  $destination = home_url('/' . trim($target, '/'));

  if (!is_user_logged_in()) {
    wpmps_log_auth('required_for_finalization', [
      'destination' => $destination,
    ]);
    wp_redirect(wp_login_url($destination));
    exit;
  }

  // Handle postMessage capture flow
  if ($is_postmessage_capture) {
    // For postMessage captures, we don't redirect immediately
    // The JavaScript will handle the modal closing and user feedback
    wpmps_log_subscription('postmessage_capture_page_loaded', [
      'query_args' => $sanitized_query_args,
      'user_id' => get_current_user_id()
    ]);
    
    // Load the page normally - the JavaScript listener will handle the rest
    return;
  }

  // Traditional back_url flow processing
  $preapproval_id = '';
  $preapproval_source = '';
  $is_postmessage_fallback = false;
  
  // Check if this user was flagged for postMessage fallback (requirement 6.2)
  $user_id = get_current_user_id();
  if ($user_id > 0) {
    $fallback_flag = get_user_meta($user_id, '_mp_fallback_to_traditional', true);
    if ($fallback_flag === 'yes') {
      $is_postmessage_fallback = true;
      // Clear the fallback flag since we're now processing traditionally
      delete_user_meta($user_id, '_mp_fallback_to_traditional');
      delete_user_meta($user_id, '_mp_postmessage_failed');
    }
  }
  
  if (isset($_GET['preapproval_id'])) {
    $preapproval_id = sanitize_text_field(wp_unslash($_GET['preapproval_id']));
    $preapproval_source = 'preapproval_id';
  } elseif (isset($_GET['id'])) {
    $preapproval_id = sanitize_text_field(wp_unslash($_GET['id']));
    $preapproval_source = 'id';
  } else {
    $first_key = array_key_first($_GET ?? []);
    if ($first_key && !in_array($first_key, ['ok','mp_err','mp_status','pm_capture'], true)) {
      $preapproval_id = sanitize_text_field(wp_unslash($_GET[$first_key]));
      $preapproval_source = sanitize_key($first_key);
    }
  }

  $success_destination = apply_filters('wpmps_finalization_redirect', home_url('/cartelera/'));

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
    'flow_type' => 'traditional',
    'is_postmessage_fallback' => $is_postmessage_fallback
  ]);

  // Use the common processing function for traditional flow
  // Mark as fallback if this came from postMessage failure (requirement 6.2)
  $flow_source = $is_postmessage_fallback ? 'traditional_fallback' : 'traditional';
  $result = wpmps_process_preapproval_capture($preapproval_id, 'traditional', $flow_source);
  
  if ($result['success']) {
    $data = $result['data'];
    if (!empty($data['redirect_url'])) {
      wp_redirect($data['redirect_url']);
      exit;
    } else {
      // Fallback redirect for non-authorized status
      $redirect_url = add_query_arg(['ok' => 1, 'mp_status' => $data['status']], $success_destination);
      wp_redirect($redirect_url);
      exit;
    }
  } else {
    // Handle error case
    $error_message = $data['message'] ?? __('No se pudo consultar el estado de la suscripción.', 'wp-mp-subscriptions');
    wpmps_log_error('finalization', 'processing_failed', 'Traditional flow processing failed', [
      'preapproval_id' => $preapproval_id,
      'error' => $error_message
    ]);
    wp_redirect(add_query_arg('mp_err', rawurlencode($error_message), $destination));
    exit;
  }
});

add_action('wp_footer', function(){
  $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
  $path = trim((string) parse_url($request_uri, PHP_URL_PATH), '/');
  if ($path !== 'finalizar-suscripcion') {
    return;
  }

  // Enqueue the postMessage listener for finalization page
  if (function_exists('wpmps_enqueue_checkout_listener')) {
    wpmps_enqueue_checkout_listener();
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
