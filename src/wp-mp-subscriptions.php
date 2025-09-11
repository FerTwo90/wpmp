<?php
/**
 * Plugin Name: AATestIntegracion MP WP — (by Devecoop)
  * Description: Suscripciones con Mercado Pago: botón → redirección segura → webhook que valida y otorga acceso.
 * Version: 0.2.0
 * Author: Devecoop
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-mp-subscriptions
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 */

if (!defined('ABSPATH')) exit;

define('WPMPS_DIR', plugin_dir_path(__FILE__));
define('WPMPS_VER', '0.2.0');

// Requiere Access Token en wp-config.php:
// define('MP_ACCESS_TOKEN', 'APP_USR-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-xxxxxx');

require_once WPMPS_DIR.'includes/helpers.php';
 require_once WPMPS_DIR.'includes/class-mp-client.php';
 require_once WPMPS_DIR.'includes/routes.php';
  require_once WPMPS_DIR.'includes/class-wpmps-sync.php';
  require_once WPMPS_DIR.'includes/class-wpmps-subscribers.php';
  require_once WPMPS_DIR.'admin/class-wpmps-admin.php';
// Ajustes (opcional)
if (file_exists(WPMPS_DIR.'includes/settings.php')) {
  require_once WPMPS_DIR.'includes/settings.php';
}

// i18n: carga el text domain
add_action('plugins_loaded', function(){
  load_plugin_textdomain('wp-mp-subscriptions', false, dirname(plugin_basename(__FILE__)).'/languages');
});

// Admin init
if (is_admin()){
  add_action('init', function(){
    if (class_exists('WPMPS_Admin')) WPMPS_Admin::init();
    // Registrar bloque Gutenberg (server) si existe
    if (function_exists('register_block_type_from_metadata')){
      register_block_type_from_metadata(WPMPS_DIR.'blocks/subscribe-button', [
        'render_callback' => function($attrs){
          $plan = isset($attrs['plan_id']) ? sanitize_text_field($attrs['plan_id']) : '';
          $label= isset($attrs['label']) ? sanitize_text_field($attrs['label']) : __('Suscribirme','wp-mp-subscriptions');
          $back = isset($attrs['back']) ? esc_url_raw($attrs['back']) : '/resultado-suscripcion';
          $sc = '[mp_subscribe '.($plan?('plan_id="'.esc_attr($plan).'" '):'').'reason="'.esc_attr($label).'" back="'.esc_attr($back).'"]';
          return do_shortcode($sc);
        }
      ]);
    }
  });
}

// Shortcode simple: [mp_subscribe amount="10000" reason="Club de Descuentos" back="/suscribirse/resultado"]
add_shortcode('mp_subscribe', function($atts){
  if (!is_user_logged_in()) {
    return '<a class="btn" href="'.esc_url(wp_login_url(get_permalink())).'">'.esc_html__('Iniciá sesión para suscribirte', 'wp-mp-subscriptions').'</a>';
  }
  $default_plan = get_option('wpmps_default_plan_id', '');
  $a = shortcode_atts([
    'plan_id'  => $default_plan, // Si está presente, usa flujo de plan
    'amount'   => '10000',      // ARS
    'reason'   => __('Suscripción', 'wp-mp-subscriptions'),
    'currency' => 'ARS',
    'back'     => '/suscribirse/resultado'
  ], $atts);

  $args = [
    'wpmps'   => 'create',
    'reason'  => $a['reason'],
    'back'    => $a['back'],
  ];
  if (!empty($a['plan_id'])) {
    $args['plan_id'] = $a['plan_id'];
  } else {
    $args['amount']   = $a['amount'];
    $args['currency'] = $a['currency'];
  }
  $url = wp_nonce_url(add_query_arg($args, home_url('/')), 'wpmps_create');

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
  $reason   = sanitize_text_field($_GET['reason'] ?? 'Suscripción');
  $back     = esc_url_raw(home_url(sanitize_text_field($_GET['back'] ?? '/')));
  $plan_id  = sanitize_text_field($_GET['plan_id'] ?? '');
  $amount   = floatval($_GET['amount'] ?? 0);
  $currency = sanitize_text_field($_GET['currency'] ?? 'ARS');

  $client = new WPMPS_MP_Client($token);
  if (!empty($plan_id)) {
    // Crear preapproval basado en un plan existente
    $body = [
      'reason'              => $reason,
      'payer_email'         => $u->user_email,
      'back_url'            => $back,
      'preapproval_plan_id' => $plan_id,
    ];
  } else {
    // Flujo legacy por monto directo
    $body = [
      'reason'        => $reason,
      'payer_email'   => $u->user_email,
      'back_url'      => $back,
      'auto_recurring'=> [
        'frequency'         => 1,
        'frequency_type'    => 'months',
        'transaction_amount'=> $amount,
        'currency_id'       => $currency
      ],
    ];
  }

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
