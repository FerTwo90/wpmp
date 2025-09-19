<?php
/**
 * Plugin Name: AATestIntegracion MP WP — (by Devecoop)
  * Description: Suscripciones con Mercado Pago: botón → redirección segura → webhook que valida y otorga acceso.
 * Version: 0.3.3
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
define('WPMPS_VER', '0.3.3');

// Requiere Access Token en wp-config.php:
// define('MP_ACCESS_TOKEN', 'APP_USR-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-xxxxxx');

require_once WPMPS_DIR.'includes/helpers.php';
require_once WPMPS_DIR.'includes/class-wpmps-logger.php';
require_once WPMPS_DIR.'includes/class-mp-client.php';
require_once WPMPS_DIR.'includes/shortcodes.php';
require_once WPMPS_DIR.'includes/routes.php';
require_once WPMPS_DIR.'includes/class-wpmps-sync.php';
require_once WPMPS_DIR.'includes/class-wpmps-subscribers.php';
require_once WPMPS_DIR.'admin/class-wpmps-admin.php';
require_once WPMPS_DIR.'admin/class-wpmps-logs.php';
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
    if (class_exists('WPMPS_Logs_Admin')) WPMPS_Logs_Admin::init();
  });
}

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
