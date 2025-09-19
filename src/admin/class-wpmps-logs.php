<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Logs_Admin {
  public static function init(){
    add_action('admin_post_wpmps_log_clear', [__CLASS__, 'clear']);
    add_action('admin_post_wpmps_log_download', [__CLASS__, 'download']);
  }

  public static function clear(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_log_clear');
    if (class_exists('WPMPS_Logger')) WPMPS_Logger::clear();
    wp_redirect(admin_url('admin.php?page=wpmps-logs'));
    exit;
  }

  public static function download(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_log_download');
    if (class_exists('WPMPS_Logger')) WPMPS_Logger::download_ndjson();
    wp_die('');
  }
}

