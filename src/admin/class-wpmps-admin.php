<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Admin {
  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_wpmps_sync_plans', [__CLASS__, 'handle_sync_plans']);
    add_action('admin_post_wpmps_export_csv', [__CLASS__, 'handle_export_csv']);
    add_action('admin_post_wpmps_refresh_sub', [__CLASS__, 'handle_refresh_subscriber']);
    add_action('admin_post_wpmps_refresh_all', [__CLASS__, 'handle_refresh_all']);
    add_action('admin_post_wpmps_reprocess', [__CLASS__, 'handle_reprocess']);
    add_action('admin_post_wpmps_save_mail', [__CLASS__, 'handle_save_mail']);
    add_action('admin_post_wpmps_deactivate_subscriber', [__CLASS__, 'deactivate_subscriber']);
    add_action('admin_post_wpmps_change_to_pending', [__CLASS__, 'change_to_pending']);
    add_action('admin_post_wpmps_cleanup_old_tokens', [__CLASS__, 'cleanup_old_tokens']);
    add_action('admin_post_wpmps_clear_cache', [__CLASS__, 'clear_cache']);
    add_action('admin_post_wpmps_refresh_background', [__CLASS__, 'refresh_background']);
    add_action('admin_post_wpmps_clear_payments_cache', [__CLASS__, 'clear_payments_cache']);
    add_action('admin_post_wpmps_sync_payments_subscriptions', [__CLASS__, 'handle_sync_payments_subscriptions']);
    add_action('admin_post_wpmps_force_sync_payments', [__CLASS__, 'handle_force_sync_payments']);
    add_action('admin_post_wpmps_clear_sync_cache', [__CLASS__, 'handle_clear_sync_cache']);
    add_action('admin_post_wpmps_reset_payments_table', [__CLASS__, 'handle_reset_payments_table']);
    add_action('wp_ajax_wpmps_search_users', [__CLASS__, 'handle_search_users']);
    add_action('wp_ajax_wpmps_associate_user', [__CLASS__, 'handle_associate_user']);
    add_filter('default_content', [__CLASS__, 'maybe_inject_shortcode'], 10, 2);
  }

  public static function menu(){
    $cap = 'manage_options';
    add_menu_page(
      __('MP Subscriptions', 'wp-mp-subscriptions'),
      __('MP Subscriptions', 'wp-mp-subscriptions'),
      $cap,
      'wpmps',
      [__CLASS__, 'render_settings'],
      'dashicons-groups',
      56
    );
    add_submenu_page('wpmps', __('Ajustes', 'wp-mp-subscriptions'), __('Ajustes', 'wp-mp-subscriptions'), $cap, 'wpmps-settings', [__CLASS__, 'render_settings']);
    add_submenu_page('wpmps', __('Planes', 'wp-mp-subscriptions'), __('Planes', 'wp-mp-subscriptions'), $cap, 'wpmps-plans', [__CLASS__, 'render_plans']);
    // add_submenu_page('wpmps', __('Suscriptores', 'wp-mp-subscriptions'), __('Suscriptores', 'wp-mp-subscriptions'), $cap, 'wpmps-subscribers', [__CLASS__, 'render_subscribers']);
    // add_submenu_page('wpmps', __('Pagos MP', 'wp-mp-subscriptions'), __('Pagos MP', 'wp-mp-subscriptions'), $cap, 'wpmps-payments', [__CLASS__, 'render_payments']);
    add_submenu_page('wpmps', __('Mail', 'wp-mp-subscriptions'), __('Mail', 'wp-mp-subscriptions'), $cap, 'wpmps-mail', [__CLASS__, 'render_mail']);
    add_submenu_page('wpmps', __('Cron', 'wp-mp-subscriptions'), __('Cron', 'wp-mp-subscriptions'), $cap, 'wpmps-cron', [__CLASS__, 'render_cron']);
    add_submenu_page('wpmps', __('Pagos y Suscripciones', 'wp-mp-subscriptions'), __('Pagos y Suscripciones', 'wp-mp-subscriptions'), $cap, 'wpmps-payments-subscriptions', [__CLASS__, 'render_payments_subscriptions']);
    add_submenu_page('wpmps', __('Logs', 'wp-mp-subscriptions'), __('Logs', 'wp-mp-subscriptions'), $cap, 'wpmps-logs', [__CLASS__, 'render_logs']);
  }

  private static function view($name, $vars = []){
    $file = WPMPS_DIR.'admin/views/'.$name.'.php';
    if (file_exists($file)){
      extract($vars);
      include $file;
    } else {
      echo '<div class="wrap"><h1>'.esc_html__('Vista no encontrada', 'wp-mp-subscriptions').'</h1></div>';
    }
  }

  private static function tabs($active){
    $tabs = [
      'wpmps-settings'   => __('Ajustes','wp-mp-subscriptions'),
      'wpmps-plans'      => __('Planes','wp-mp-subscriptions'),
      // 'wpmps-subscribers'=> __('Suscriptores','wp-mp-subscriptions'),
      // 'wpmps-payments'   => __('Pagos MP','wp-mp-subscriptions'),
      'wpmps-mail'       => __('Mail','wp-mp-subscriptions'),
      'wpmps-cron'       => __('Cron','wp-mp-subscriptions'),
      'wpmps-payments-subscriptions' => __('Pagos y Suscripciones','wp-mp-subscriptions'),
      'wpmps-logs'       => __('Logs','wp-mp-subscriptions'),
    ];
    echo '<h1 class="nav-tab-wrapper">';
    foreach ($tabs as $slug=>$label){
      $class = ($active === $slug) ? ' nav-tab-active' : '';
      echo '<a class="nav-tab'.$class.'" href="'.esc_url(admin_url('admin.php?page='.$slug)).'">'.esc_html($label).'</a>';
    }
    echo '</h1>';
  }

  public static function render_settings(){
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('WP MP Subscriptions — Ajustes', 'wp-mp-subscriptions').'</h1>';
    self::tabs('wpmps-settings');
    self::view('settings');
    echo '</div>';
  }

  public static function render_plans(){
    if (!current_user_can('manage_options')) return;
    $plans = WPMPS_Sync::get_plans();
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Planes', 'wp-mp-subscriptions').'</h1>';
    self::tabs('wpmps-plans');
    self::view('plans', ['plans'=>$plans]);
    echo '</div>';
  }

  public static function render_mail(){
    if (!current_user_can('manage_options')) return;
    $opts = [
      'enabled' => get_option('wpmps_mail_enabled', ''),
      'format'  => get_option('wpmps_mail_format', 'text'),
      'from_name' => get_option('wpmps_mail_from_name', 'Hoy Salgo'),
      'from_email' => get_option('wpmps_mail_from_email', 'info@hoysalgo.com'),
      'subject' => get_option('wpmps_mail_subject', ''),
      'body'    => get_option('wpmps_mail_body', ''),
    ];
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Mail', 'wp-mp-subscriptions').'</h1>';
    self::tabs('wpmps-mail');
    self::view('mail', ['mail_opts'=>$opts]);
    echo '</div>';
  }

  public static function render_cron(){
    if (!current_user_can('manage_options')) return;
    
    $status = WPMPS_Cron::get_status();
    
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Cron de Suscripciones', 'wp-mp-subscriptions').'</h1>';
    self::tabs('wpmps-cron');
    
    // Show success message if cron was run manually
    if (isset($_GET['cron_run'])) {
      echo '<div class="notice notice-success is-dismissible"><p>';
      echo esc_html__('Cron ejecutado manualmente con éxito.', 'wp-mp-subscriptions');
      echo '</p></div>';
    }
    
    self::view('cron', ['status' => $status]);
    echo '</div>';
  }

  public static function render_payments_subscriptions(){
    if (!current_user_can('manage_options')) return;

    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('render_pagos_y_suscripciones_start', []);
    }

    // PASO 1: Tengo todas las suscripciones? (sino buscar las que falten)
    $seed_result = WPMPS_Payments_Subscriptions::bootstrap_subscriptions_if_empty(100);
    $smart_sync_result = WPMPS_Payments_Subscriptions::smart_sync_subscriptions(100);
    
    // PASO 2 y 3: Completar datos faltantes de pagos (reutilizando entre filas)
    $payments_seed_result = WPMPS_Payments_Subscriptions::complete_payment_data();
    
    // PASO 4: Mapear usuarios de WordPress
    $user_mapping_result = WPMPS_Payments_Subscriptions::map_users_to_subscriptions();

    $filters = array_filter([
      'status' => isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '',
      'plan_id' => isset($_GET['filter_plan_id']) ? sanitize_text_field($_GET['filter_plan_id']) : '',
      'preapproval_id' => isset($_GET['filter_preapproval_id']) ? sanitize_text_field($_GET['filter_preapproval_id']) : '',
      'payer_identification' => isset($_GET['filter_document']) ? sanitize_text_field($_GET['filter_document']) : '',
      'payment_id' => isset($_GET['filter_payment_id']) ? sanitize_text_field($_GET['filter_payment_id']) : '',
      'orderby' => isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at',
      'order' => isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) ? strtoupper($_GET['order']) : 'DESC',
      'limit' => isset($_GET['filter_limit']) ? max(10, min(100, intval($_GET['filter_limit']))) : 50,
      'offset' => isset($_GET['paged']) ? max(0, (intval($_GET['paged']) - 1) * 50) : 0
    ]);
    
    $subscriptions_data = WPMPS_Payments_Subscriptions::get_subscriptions($filters);
    
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Pagos y Suscripciones', 'wp-mp-subscriptions').'</h1>';
    self::tabs('wpmps-payments-subscriptions');
    self::view('payments-subscriptions', [
      'subscriptions_data'  => $subscriptions_data,
      'filters'             => $filters,
      'seed_result'         => $seed_result,
      'smart_sync_result'   => $smart_sync_result,
      'user_mapping_result' => $user_mapping_result,
      'payments_seed_result'=> $payments_seed_result
    ]);
    echo '</div>';

  }


  public static function render_logs(){
    if (!current_user_can('manage_options')) return;
    $events = get_option('wpmps_webhook_events', []);
    $events = is_array($events) ? array_reverse(array_slice($events, -50)) : [];
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Logs / Webhooks', 'wp-mp-subscriptions').'</h1>';
    self::tabs('wpmps-logs');
    self::view('logs', ['events'=>$events]);
    echo '</div>';
  }

  public static function handle_sync_plans(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_sync_plans');
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('sync_plans', []);
    }
    WPMPS_Sync::clear_cache();
    WPMPS_Sync::get_plans(true);
    wp_redirect(admin_url('admin.php?page=wpmps-plans'));
    exit;
  }

  public static function handle_export_csv(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_export_csv');
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('export_csv', []);
    }
    $rows = WPMPS_Subscribers::get_subscribers();
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wpmps-subscribers.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email','user_id','preapproval_id','plan_id','status','updated_at']);
    foreach ($rows as $r){
      fputcsv($out, [$r['email'],$r['user_id'],$r['preapproval_id'],$r['plan_id'],$r['status'],$r['updated_at']]);
    }
    fclose($out);
    exit;
  }

  public static function handle_refresh_subscriber(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_refresh_sub');
    $uid = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('refresh_subscriber', ['user_id'=>$uid]);
    }
    if ($uid){
      WPMPS_Subscribers::refresh_subscriber($uid);
      // Limpiar caché después de actualizar
      WPMPS_Subscribers::clear_cache();
    }
    wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    exit;
  }

  public static function handle_refresh_all(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_refresh_all');
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('refresh_all_subscribers', []);
    }
    
    // Limpiar caché primero para obtener datos frescos
    WPMPS_Subscribers::clear_cache();
    
    // Usar método optimizado que limita las llamadas MP
    $updated = WPMPS_Subscribers::refresh_subscribers_background(20); // Máximo 20 usuarios
    
    wp_redirect(add_query_arg('refreshed', $updated, admin_url('admin.php?page=wpmps-subscribers')));
    exit;
  }

  public static function handle_save_mail(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_mail_save');

    $enabled = isset($_POST['wpmps_mail_enabled']) ? 'yes' : '';
    $format  = isset($_POST['wpmps_mail_format']) ? sanitize_text_field($_POST['wpmps_mail_format']) : 'text';
    $from_name = isset($_POST['wpmps_mail_from_name']) ? sanitize_text_field(wp_unslash($_POST['wpmps_mail_from_name'])) : 'Hoy Salgo';
    $from_email = isset($_POST['wpmps_mail_from_email']) ? sanitize_email(wp_unslash($_POST['wpmps_mail_from_email'])) : 'info@hoysalgo.com';
    $subject = isset($_POST['wpmps_mail_subject']) ? sanitize_text_field(wp_unslash($_POST['wpmps_mail_subject'])) : '';
    $body    = isset($_POST['wpmps_mail_body']) ? wp_kses_post(wp_unslash($_POST['wpmps_mail_body'])) : '';

    // Validate format
    if (!in_array($format, ['text', 'html'])) {
      $format = 'text';
    }

    // Validate email
    if (!is_email($from_email)) {
      $from_email = 'info@hoysalgo.com';
    }

    update_option('wpmps_mail_enabled', $enabled, false);
    update_option('wpmps_mail_format', $format, false);
    update_option('wpmps_mail_from_name', $from_name, false);
    update_option('wpmps_mail_from_email', $from_email, false);
    update_option('wpmps_mail_subject', $subject, false);
    update_option('wpmps_mail_body', $body, false);

    if (function_exists('wpmps_log_admin')) {
      wpmps_log_admin('save_mail_settings', [
        'enabled' => $enabled ? 'yes' : 'no',
        'format' => $format,
      ]);
    }

    wp_redirect(add_query_arg('updated', 1, admin_url('admin.php?page=wpmps-mail')));
    exit;
  }

  public static function handle_reprocess(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_reprocess');
    $pre_id = sanitize_text_field($_GET['preapproval_id'] ?? '');
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('reprocess_webhook', ['preapproval_id'=>$pre_id]);
    }
    if ($pre_id){
      $token = function_exists('wpmps_get_access_token') ? wpmps_get_access_token() : '';
      if ($token){
        $client = new WPMPS_MP_Client($token);
        $resp = $client->get_preapproval($pre_id);
        if ($resp['http'] === 200){
          $pre = $resp['body'];
          $email  = sanitize_email($pre['payer_email'] ?? '');
          $status = sanitize_text_field($pre['status'] ?? '');
          if ($email){
            $user = get_user_by('email', $email);
            if ($user){
              $active = ($status === 'authorized') ? 'yes' : 'no';
              update_user_meta($user->ID, '_suscripcion_activa', $active);
              if (!empty($pre['id'])) update_user_meta($user->ID, '_mp_preapproval_id', sanitize_text_field($pre['id']));
              if (!empty($pre['preapproval_plan_id'])) update_user_meta($user->ID, '_mp_plan_id', sanitize_text_field($pre['preapproval_plan_id']));
            }
          }
        }
      }
    }
    wp_redirect(admin_url('admin.php?page=wpmps-logs'));
    exit;
  }

  public static function maybe_inject_shortcode($content, $post){
    if (!is_admin()) return $content;
    if (!current_user_can('edit_post', $post->ID)) return $content;
    if (!empty($_GET['_wpmps_shortcode'])){
      $shortcode = wp_kses_post(wp_unslash($_GET['_wpmps_shortcode']));
      return $shortcode . "\n\n" . $content;
    }
    return $content;
  }

  public static function deactivate_subscriber(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_deactivate_subscriber');
    $uid = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('deactivate_subscriber_request', ['user_id'=>$uid]);
    }
    if ($uid && class_exists('WPMPS_Subscribers')){
      WPMPS_Subscribers::deactivate_subscriber($uid, 'admin_manual');
    }
    wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    exit;
  }

  public static function change_to_pending(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_change_to_pending');
    $uid = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('change_to_pending_request', ['user_id'=>$uid]);
    }
    if ($uid && class_exists('WPMPS_Subscribers')){
      WPMPS_Subscribers::change_to_pending_role($uid, 'mp_inactive');
    }
    wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    exit;
  }

  public static function cleanup_old_tokens(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_cleanup_old_tokens');
    
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('cleanup_old_tokens_request', []);
    }
    
    if (class_exists('WPMPS_Subscribers')){
      $result = WPMPS_Subscribers::cleanup_old_token_data();
      
      if ($result['success']) {
        $message = $result['message'];
        if (!empty($result['errors'])) {
          $message .= ' (Con algunos errores)';
        }
        wp_redirect(add_query_arg(['cleaned' => $result['cleaned_count']], admin_url('admin.php?page=wpmps-subscribers')));
      } else {
        wp_redirect(add_query_arg(['error' => urlencode($result['message'])], admin_url('admin.php?page=wpmps-subscribers')));
      }
    } else {
      wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    }
    exit;
  }

  public static function clear_cache(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_clear_cache');
    
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('clear_cache_request', []);
    }
    
    WPMPS_Subscribers::clear_cache();
    
    wp_redirect(add_query_arg('cache_cleared', 1, admin_url('admin.php?page=wpmps-subscribers')));
    exit;
  }

  public static function refresh_background(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_refresh_background');
    
    if (function_exists('wpmps_log_admin')){
      wpmps_log_admin('refresh_background_request', []);
    }
    
    $updated = WPMPS_Subscribers::refresh_subscribers_background(10);
    
    wp_redirect(add_query_arg('background_updated', $updated, admin_url('admin.php?page=wpmps-subscribers')));
    exit;
  }

  /**
   * Handler para sincronizar pagos y suscripciones
   */
  public static function handle_sync_payments_subscriptions(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_sync_payments_subscriptions');

    $result = WPMPS_Payments_Subscriptions::sync_payments_for_subscriptions(50);
    
    $redirect_args = [
      'sync_completed' => $result['seeded'] ? 1 : 0,
      'sync_message' => urlencode($result['message'])
    ];
    
    if (isset($result['inserted'])) {
      $redirect_args['sync_inserted'] = $result['inserted'];
    }
    
    wp_redirect(add_query_arg($redirect_args, admin_url('admin.php?page=wpmps-payments-subscriptions')));
    exit;
  }

  /**
   * Handler para forzar sincronización de pagos
   */
  public static function handle_force_sync_payments(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_force_sync_payments');

    $result = WPMPS_Payments_Subscriptions::force_sync_all_payments(100);
    
    $redirect_args = [
      'force_sync_completed' => $result['seeded'] ? 1 : 0,
      'force_sync_message' => urlencode($result['message'])
    ];
    
    if (isset($result['inserted'])) {
      $redirect_args['force_sync_inserted'] = $result['inserted'];
    }
    
    wp_redirect(add_query_arg($redirect_args, admin_url('admin.php?page=wpmps-payments-subscriptions')));
    exit;
  }

  /**
   * Handler para limpiar caché de sincronización
   */
  public static function handle_clear_sync_cache(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_clear_sync_cache');

    WPMPS_Payments_Subscriptions::clear_sync_cache();
    
    wp_redirect(add_query_arg('sync_cache_cleared', 1, admin_url('admin.php?page=wpmps-payments-subscriptions')));
    exit;
  }

  /**
   * Handler para resetear la tabla de pagos y suscripciones
   */
  public static function handle_reset_payments_table(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_reset_payments_table');

    $result = WPMPS_Payments_Subscriptions::reset_table();
    
    $redirect_args = [
      'table_reset' => $result['success'] ? 1 : 0,
      'reset_message' => urlencode($result['message'])
    ];
    
    wp_redirect(add_query_arg($redirect_args, admin_url('admin.php?page=wpmps-payments-subscriptions')));
    exit;
  }

  /**
   * Handler AJAX para buscar usuarios
   */
  public static function handle_search_users() {
    if (!current_user_can('manage_options')) wp_die('');
    check_ajax_referer('wpmps_search_users', 'nonce');

    $query = sanitize_text_field($_POST['query'] ?? '');
    $exclude = sanitize_text_field($_POST['exclude'] ?? '');
    $exclude_ids = !empty($exclude) ? array_map('intval', explode(',', $exclude)) : [];

    if (strlen($query) < 2) {
      wp_send_json([]);
    }

    $args = [
      'search' => '*' . $query . '*',
      'search_columns' => ['user_login', 'user_email', 'display_name'],
      'number' => 20,
      'exclude' => $exclude_ids
    ];

    $users = get_users($args);
    $results = [];

    foreach ($users as $user) {
      $results[] = [
        'ID' => $user->ID,
        'user_email' => $user->user_email,
        'display_name' => $user->display_name,
        'roles' => $user->roles
      ];
    }

    wp_send_json($results);
  }

  /**
   * Handler AJAX para asociar usuario
   */
  public static function handle_associate_user() {
    if (!current_user_can('manage_options')) wp_die('');
    check_ajax_referer('wpmps_associate_user', 'nonce');

    $sub_id = intval($_POST['sub_id'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);

    if (!$sub_id || !$user_id) {
      wp_send_json_error(__('Datos inválidos.', 'wp-mp-subscriptions'));
    }

    global $wpdb;
    $table = WPMPS_Payments_Subscriptions::table_name();
    
    $result = $wpdb->update(
      $table,
      ['user_id' => $user_id],
      ['id' => $sub_id]
    );

    if ($result !== false) {
      wp_send_json_success(__('Usuario asociado correctamente.', 'wp-mp-subscriptions'));
    } else {
      wp_send_json_error(__('Error al asociar usuario.', 'wp-mp-subscriptions'));
    }
  }

}
