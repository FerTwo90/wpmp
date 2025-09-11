<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Admin {
  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_wpmps_sync_plans', [__CLASS__, 'handle_sync_plans']);
    add_action('admin_post_wpmps_export_csv', [__CLASS__, 'handle_export_csv']);
    add_action('admin_post_wpmps_refresh_sub', [__CLASS__, 'handle_refresh_subscriber']);
    add_action('admin_post_wpmps_reprocess', [__CLASS__, 'handle_reprocess']);
    add_filter('default_content', [__CLASS__, 'maybe_inject_shortcode'], 10, 2);
  }

  public static function menu(){
    $cap = 'manage_options';
    add_menu_page(
      __('MP Subscriptions', 'wp-mp-subscriptions'),
      __('MP Subscriptions', 'wp-mp-subscriptions'),
      $cap,
      'wpmps',
      [__CLASS__, 'render_plans'],
      'dashicons-groups',
      56
    );
    add_submenu_page('wpmps', __('Planes', 'wp-mp-subscriptions'), __('Planes', 'wp-mp-subscriptions'), $cap, 'wpmps', [__CLASS__, 'render_plans']);
    add_submenu_page('wpmps', __('Botones', 'wp-mp-subscriptions'), __('Botones', 'wp-mp-subscriptions'), $cap, 'wpmps-buttons', [__CLASS__, 'render_buttons']);
    add_submenu_page('wpmps', __('Suscriptores', 'wp-mp-subscriptions'), __('Suscriptores', 'wp-mp-subscriptions'), $cap, 'wpmps-subscribers', [__CLASS__, 'render_subscribers']);
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

  public static function render_plans(){
    if (!current_user_can('manage_options')) return;
    $plans = WPMPS_Sync::get_plans();
    self::view('plans', ['plans'=>$plans]);
  }

  public static function render_buttons(){
    if (!current_user_can('manage_options')) return;
    $plans = WPMPS_Sync::get_plans();
    $has_blocks = function_exists('register_block_type');
    self::view('buttons', ['plans'=>$plans, 'has_blocks'=>$has_blocks]);
  }

  public static function render_subscribers(){
    if (!current_user_can('manage_options')) return;
    $subs = WPMPS_Subscribers::get_subscribers();
    self::view('subscribers', ['subs'=>$subs]);
  }

  public static function render_logs(){
    if (!current_user_can('manage_options')) return;
    $events = get_option('wpmps_webhook_events', []);
    $events = is_array($events) ? array_reverse(array_slice($events, -50)) : [];
    self::view('logs', ['events'=>$events]);
  }

  public static function handle_sync_plans(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_sync_plans');
    WPMPS_Sync::clear_cache();
    WPMPS_Sync::get_plans(true);
    wp_redirect(admin_url('admin.php?page=wpmps'));
    exit;
  }

  public static function handle_export_csv(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_export_csv');
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
    if ($uid){
      WPMPS_Subscribers::refresh_subscriber($uid);
    }
    wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    exit;
  }

  public static function handle_reprocess(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_reprocess');
    $pre_id = sanitize_text_field($_GET['preapproval_id'] ?? '');
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
}

