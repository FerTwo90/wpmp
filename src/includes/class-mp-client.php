<?php
if (!defined('ABSPATH')) exit;

class WPMPS_MP_Client {
  private $token;
  private $api = 'https://api.mercadopago.com';

  public function __construct($token){ $this->token = $token; }

  private function headers($idempotency = false){
    $h = [
      'Authorization' => 'Bearer '.$this->token,
      'Content-Type'  => 'application/json'
    ];
    if ($idempotency) $h['X-Idempotency-Key'] = wp_generate_uuid4();
    return $h;
  }

  public function create_preapproval($payload){
    // Log before request (safe preview)
    $preview = [
      'method' => 'POST', 'path' => '/preapproval',
      'payer_email' => isset($payload['payer_email']) ? sanitize_email($payload['payer_email']) : '',
      'back_url' => isset($payload['back_url']) ? esc_url_raw($payload['back_url']) : '',
      'has_plan_id' => !empty($payload['preapproval_plan_id']),
      'has_auto_recurring' => !empty($payload['auto_recurring']),
    ];
    if (function_exists('wpmps_collect_context') && function_exists('wpmps_log')){
      wpmps_log('CREATE', wpmps_collect_context('http_before', $preview));
    }
    $res = wp_remote_post($this->api.'/preapproval', [
      'headers' => $this->headers(true),
      'body'    => wp_json_encode($payload),
      'timeout' => 20
    ]);
    $out = $this->normalize_response($res);
    if (function_exists('wpmps_collect_context') && function_exists('wpmps_log')){
      wpmps_log('CREATE', wpmps_collect_context('http_after', [
        'method'=>'POST','path'=>'/preapproval','http_code'=>$out['http'] ?? 0
      ]));
    }
    return $out;
  }

  public function get_preapproval($id){
    if (function_exists('wpmps_collect_context') && function_exists('wpmps_log')){
      wpmps_log('WEBHOOK', wpmps_collect_context('http_before', [
        'method'=>'GET','path'=>'/preapproval/{id}','id'=> sanitize_text_field($id)
      ]));
    }
    $res = wp_remote_get($this->api.'/preapproval/'.rawurlencode($id), [
      'headers' => $this->headers(),
      'timeout' => 20
    ]);
    $out = $this->normalize_response($res);
    if (function_exists('wpmps_collect_context') && function_exists('wpmps_log')){
      wpmps_log('WEBHOOK', wpmps_collect_context('http_after', [
        'method'=>'GET','path'=>'/preapproval/{id}','http_code'=>$out['http'] ?? 0
      ]));
    }
    return $out;
  }

  private function normalize_response($res){
    if (is_wp_error($res)) {
      $err = ['code'=>$res->get_error_code(), 'message'=>$res->get_error_message()];
      if (function_exists('wpmps_collect_context') && function_exists('wpmps_log')){
        wpmps_log('ERROR', wpmps_collect_context('http_error', $err));
      }
      return ['http'=>0,'body'=>['error'=>$res->get_error_message()]];
    }
    $http = wp_remote_retrieve_response_code($res);
    $body_raw = wp_remote_retrieve_body($res);
    $parsed = json_decode($body_raw, true);
    $out = ['http'=>$http,'body'=> $parsed ?: []];
    if (!$parsed && is_string($body_raw) && $body_raw !== ''){
      // Preserve a safe preview to help diagnose non-JSON errors
      $out['raw_body'] = substr($body_raw, 0, 500);
    }
    // Include request id if present
    $headers = wp_remote_retrieve_headers($res);
    if ($headers && isset($headers['x-request-id'])){
      $out['request_id'] = $headers['x-request-id'];
    }
    return $out;
  }

  // ----- Plans (best-effort, may vary by region)
  public function get_preapproval_plan($id){
    $res = wp_remote_get($this->api.'/preapproval_plan/'.rawurlencode($id), [
      'headers' => $this->headers(),
      'timeout' => 20
    ]);
    return $this->normalize_response($res);
  }

  public function search_preapproval_plans($params = []){
    $query = http_build_query($params);
    $url = $this->api.'/preapproval_plan/search'.($query?('?'.$query):'');
    $res = wp_remote_get($url, [
      'headers' => $this->headers(),
      'timeout' => 20
    ]);
    return $this->normalize_response($res);
  }

  // List preapprovals (subscriptions) for the account
  public function search_preapprovals($params = []){
    $query = http_build_query($params);
    $url = $this->api.'/preapproval/search'.($query?('?'.$query):'');
    $res = wp_remote_get($url, [
      'headers' => $this->headers(),
      'timeout' => 20
    ]);
    return $this->normalize_response($res);
  }
}
