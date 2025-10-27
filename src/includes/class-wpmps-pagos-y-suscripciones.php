<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Payments_Subscriptions {

  /**
   * Obtiene pagos reutilizando la lógica existente del módulo de pagos.
   */
  public static function get_payments($filters = []) {

    return [
      'success'  => false,
      'message'  => __('La funcion está en desarrollo', 'wp-mp-subscriptions'),
      'payments' => []
    ];
  }

  /**
   * Obtiene suscripciones básicas desde el módulo de suscriptores.
   */
  public static function get_subscriptions($filters = []) {


    return [
      'success'       => false,
      'subscriptions' => [],
      'message'       => __('La funcion está en desarrollo', 'wp-mp-subscriptions')
    ];
  }
}
