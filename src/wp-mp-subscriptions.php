<?php
/**
 * Plugin Name: WP MP Subscriptions — Preapproval (by Devecoop)
 * Plugin URI: https://TODO-devecoop.example/plugins/wp-mp-subscriptions
 * Description: Suscripciones automáticas con Mercado Pago (Preapproval): botón → redirección segura → webhook que valida y otorga acceso.
 * Version: 0.1.1
 * Author: Devecoop
 * Author URI: https://TODO-devecoop.example
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-mp-subscriptions
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * Update URI: https://TODO-devecoop.example/plugins/wp-mp-subscriptions/update
 */

if (!defined('ABSPATH')) exit;

define('WPMPS_DIR', plugin_dir_path(__FILE__));
define('WPMPS_VER', '0.1.1');

// Requiere Access Token en wp-config.php:
// define('MP_ACCESS_TOKEN', 'APP_USR-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-xxxxxx');

require_once WPMPS_DIR.'includes/helpers.php';
require_once WPMPS_DIR.'includes/class-mp-client.php';
require_once WPMPS_DIR.'includes/routes.php';
// Ajustes (opcional)
if (file_exists(WPMPS_DIR.'includes/settings.php')) {
  require_once WPMPS_DIR.'includes/settings.php';
}

// i18n: carga el text domain
add_action('plugins_loaded', function(){
  load_plugin_textdomain('wp-mp-subscriptions', false, dirname(plugin_basename(__FILE__)).'/languages');
});

// Shortcode simple: [mp_subscribe amount="10000" reason="Club de Descuentos" back="/suscribirse/resultado"]
add_shortcode('mp_subscribe', function($atts){
  if (!is_user_logged_in()) {
    return '<a class="btn" href="'.esc_url(wp_login_url(get_permalink())).'">'.esc_html__('Iniciá sesión para suscribirte', 'wp-mp-subscriptions').'</a>';
  }
  $a = shortcode_atts([
    'amount'   => '10000',      // ARS
    'reason'   => __('Suscripción', 'wp-mp-subscriptions'),
    'currency' => 'ARS',
    'back'     => '/suscribirse/resultado'
  ], $atts);

  $url = wp_nonce_url(add_query_arg([
    'wpmps'   => 'create',
    'amount'  => $a['amount'],
    'reason'  => $a['reason'],
    'currency'=> $a['currency'],
    'back'    => $a['back'],
  ], home_url('/')), 'wpmps_create');

  return '<a class="btn" href="'.esc_url($url).'">'.esc_html__('Suscribirme', 'wp-mp-subscriptions').'</a>';
});

// Captura de la acción del botón (server-side) → crea preapproval y redirige a MP
add_action('template_redirect', function(){
  if (!isset($_GET['wpmps']) || $_GET['wpmps'] !== 'create') return;
  if (!is_user_logged_in()) wp_die(__('Debés iniciar sesión.', 'wp-mp-subscriptions'));
  if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wpmps_create')) wp_die(__('Nonce inválido', 'wp-mp-subscriptions'));

  $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : '');
  if (empty($token)) wp_die(__('Falta Access Token de Mercado Pago. Configuralo en Ajustes → WPMPS o en wp-config.php', 'wp-mp-subscriptions'));

  $u = wp_get_current_user();
  $amount   = floatval($_GET['amount'] ?? 0);
  $reason   = sanitize_text_field($_GET['reason'] ?? 'Suscripción');
  $currency = sanitize_text_field($_GET['currency'] ?? 'ARS');
  $back     = esc_url_raw(home_url(sanitize_text_field($_GET['back'] ?? '/')));

  $client = new WPMPS_MP_Client($token);
  $body = [
    'reason'        => $reason,
    'payer_email'   => $u->user_email,
    'back_url'      => $back,
    'auto_recurring'=> [
      'frequency'        => 1,
      'frequency_type'   => 'months',
      'transaction_amount'=> $amount,
      'currency_id'      => $currency
    ],
  ];

  $resp = $client->create_preapproval($body);
  if ($resp['http'] === 201 && !empty($resp['body']['init_point'])) {
    wp_redirect($resp['body']['init_point']); exit;
  }

  wpmps_log('MP preapproval error', $resp);
  wp_die(__('No se pudo iniciar la suscripción. Intentá más tarde.', 'wp-mp-subscriptions'));
});

// Acciones: Ajustes / Docs
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
  $links[] = '<a href="'.admin_url('options-general.php?page=wpmps-settings').'">'.__('Ajustes', 'wp-mp-subscriptions').'</a>';
  $links[] = '<a href="https://TODO-devecoop.example/docs/wp-mp-subscriptions" target="_blank">'.__('Docs', 'wp-mp-subscriptions').'</a>';
  return $links;
});

// Meta: Changelog / Soporte / Security
add_filter('plugin_row_meta', function($links, $file){
  if ($file === plugin_basename(__FILE__)) {
    $links[] = '<a href="https://TODO-devecoop.example/docs/wp-mp-subscriptions/changelog" target="_blank">'.esc_html__('Changelog', 'wp-mp-subscriptions').'</a>';
    $links[] = '<a href="https://TODO-devecoop.example/soporte" target="_blank">'.esc_html__('Soporte', 'wp-mp-subscriptions').'</a>';
    $links[] = '<a href="https://TODO-devecoop.example/security" target="_blank">'.esc_html__('Política de Seguridad', 'wp-mp-subscriptions').'</a>';
  }
  return $links;
}, 10, 2);
