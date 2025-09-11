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
    $res = wp_remote_post($this->api.'/preapproval', [
      'headers' => $this->headers(true),
      'body'    => wp_json_encode($payload),
      'timeout' => 20
    ]);
    return $this->normalize_response($res);
  }

  public function get_preapproval($id){
    $res = wp_remote_get($this->api.'/preapproval/'.rawurlencode($id), [
      'headers' => $this->headers(),
      'timeout' => 20
    ]);
    return $this->normalize_response($res);
  }

  private function normalize_response($res){
    if (is_wp_error($res)) {
      return ['http'=>0,'body'=>['error'=>$res->get_error_message()]];
    }
    $http = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);
    return ['http'=>$http,'body'=>$body ?: []];
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
}
