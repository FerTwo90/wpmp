<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Subscribers {

  /**
   * Obtiene las últimas suscripciones de Mercado Pago (máx 100).
   */
  public static function get_latest_subscriptions($limit = 25){
    $limit = max(1, min(100, intval($limit)));
    $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';

    if (empty($token)) {
      return [
        'success' => false,
        'message' => __('No se encontró Access Token de Mercado Pago.', 'wp-mp-subscriptions'),
        'subscriptions' => []
      ];
    }

    try {
      $client = new WPMPS_MP_Client($token);
    } catch (\Throwable $e) {
      if (function_exists('wpmps_log_error')){
        wpmps_log_error('subscribers', 'client_init_error', $e->getMessage());
      }
      return [
        'success' => false,
        'message' => __('No se pudo inicializar el cliente de Mercado Pago.', 'wp-mp-subscriptions'),
        'subscriptions' => []
      ];
    }

    $params = [
      'limit'  => $limit,
    ];

    $response = $client->search_preapprovals($params);
    $http_code = $response['http'] ?? 0;
    $body = $response['body'] ?? [];

    if ($http_code !== 200 || empty($body['results'])) {
      if (function_exists('wpmps_log_error')){
        $body_preview = is_scalar($body) ? $body : wp_json_encode($body);
        if (is_string($body_preview) && strlen($body_preview) > 500) {
          $body_preview = substr($body_preview, 0, 500) . '...';
        }
        wpmps_log_error('subscribers', 'mp_fetch_error', 'No se pudieron obtener suscripciones', [
          'http_code' => $http_code,
          'params'    => $params,
          'body_keys' => is_array($body) ? array_keys($body) : [],
          'body'      => $body_preview,
        ]);
      }
      return [
        'success' => false,
        'message' => __('Mercado Pago no devolvió resultados.', 'wp-mp-subscriptions'),
        'subscriptions' => []
      ];
    }

    $normalized = array_map([__CLASS__, 'normalize_preapproval'], $body['results']);

    return [
      'success'        => true,
      'subscriptions'  => $normalized,
      'raw'            => $body,
      'limit'          => $limit
    ];
  }

  /**
   * Normaliza la suscripción de MP para usarla dentro del plugin.
   */
  private static function normalize_preapproval($item){
    $auto = is_array($item['auto_recurring'] ?? null) ? $item['auto_recurring'] : [];
    return [
      'preapproval_id' => sanitize_text_field($item['id'] ?? ''),
      'plan_id'        => sanitize_text_field($item['preapproval_plan_id'] ?? ''),
      'payer_email'    => sanitize_email($item['payer_email'] ?? ''),
      'status'         => sanitize_text_field($item['status'] ?? ''),
      'reason'         => sanitize_text_field($item['reason'] ?? ''),
      'amount'         => isset($auto['transaction_amount']) ? floatval($auto['transaction_amount']) : null,
      'currency'       => sanitize_text_field($auto['currency_id'] ?? ''),
      'frequency'      => isset($auto['frequency']) ? intval($auto['frequency']) : null,
      'frequency_type' => sanitize_text_field($auto['frequency_type'] ?? ''),
      'created_at'     => sanitize_text_field($item['date_created'] ?? ''),
      'updated_at'     => sanitize_text_field($item['last_modified'] ?? ''),
      'next_payment'   => sanitize_text_field($auto['next_payment_date'] ?? ''),
    ];
  }
}
