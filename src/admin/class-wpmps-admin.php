<?php
if (!defined('ABSPATH')) exit;

class WPMPS_Admin {
  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_wpmps_sync_plans', [__CLASS__, 'handle_sync_plans']);
    add_action('admin_post_wpmps_export_csv', [__CLASS__, 'handle_export_csv']);
    add_action('admin_post_wpmps_refresh_sub', [__CLASS__, 'handle_refresh_subscriber']);
    add_action('admin_post_wpmps_refresh_all', [__CLASS__, 'handle_refresh_all']);
    add_action('admin_post_wpmps_simulate_sub', [__CLASS__, 'handle_simulate_subscriber']);
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
      [__CLASS__, 'render_settings'],
      'dashicons-groups',
      56
    );
    add_submenu_page('wpmps', __('Ajustes', 'wp-mp-subscriptions'), __('Ajustes', 'wp-mp-subscriptions'), $cap, 'wpmps-settings', [__CLASS__, 'render_settings']);
    add_submenu_page('wpmps', __('Planes', 'wp-mp-subscriptions'), __('Planes', 'wp-mp-subscriptions'), $cap, 'wpmps-plans', [__CLASS__, 'render_plans']);
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

  private static function tabs($active){
    $tabs = [
      'wpmps-settings'   => __('Ajustes','wp-mp-subscriptions'),
      'wpmps-plans'      => __('Planes','wp-mp-subscriptions'),
      'wpmps-subscribers'=> __('Suscriptores','wp-mp-subscriptions'),
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

  public static function render_subscribers(){
    if (!current_user_can('manage_options')) return;
    $subs = WPMPS_Subscribers::get_subscribers();
    // Build quick map of plan_id -> name via cached plans
    $plans = WPMPS_Sync::get_plans();
    $plans_map = [];
    foreach ($plans as $p){ if (!empty($p['id'])) $plans_map[$p['id']] = ($p['name'] ?? ''); }
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Suscriptores', 'wp-mp-subscriptions').'</h1>';
    self::tabs('wpmps-subscribers');
    self::view('subscribers', ['subs'=>$subs, 'plans_map'=>$plans_map]);
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
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('admin_action', ['action'=>'wpmps_sync_plans']));
    }
    WPMPS_Sync::clear_cache();
    WPMPS_Sync::get_plans(true);
    wp_redirect(admin_url('admin.php?page=wpmps-plans'));
    exit;
  }

  public static function handle_export_csv(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_export_csv');
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('admin_action', ['action'=>'wpmps_export_csv']));
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
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('admin_action', ['action'=>'wpmps_refresh_sub','user_id'=>$uid]));
    }
    if ($uid){
      WPMPS_Subscribers::refresh_subscriber($uid);
    }
    wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    exit;
  }

  public static function handle_refresh_all(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_refresh_all');
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('admin_action', ['action'=>'wpmps_refresh_all']));
    }
    $subs = WPMPS_Subscribers::get_subscribers();
    $count = 0;
    foreach ($subs as $s){
      if (!empty($s['user_id'])){
        WPMPS_Subscribers::refresh_subscriber(intval($s['user_id']));
        $count++;
        if ($count >= 200) break; // protección simple
      }
    }
    wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    exit;
  }

  // Simula el resultado de una suscripción para un usuario (testing)
  public static function handle_simulate_subscriber(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_simulate_sub');
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $role    = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : '';

    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('simulate', ['user_id'=>$user_id,'role'=>$role]));
    }
    $result = ['ok'=>false,'user_id'=>$user_id,'role_applied'=>$role];
    if ($user_id && get_user_by('ID', $user_id)){
      $wpuser = new WP_User($user_id);
      $before = is_array($wpuser->roles) ? array_values($wpuser->roles) : [];
      $wpuser->set_role($role);
      $after  = is_array($wpuser->roles) ? array_values($wpuser->roles) : [];
      $result['ok'] = in_array($role, $after, true);
      $result['roles_before'] = $before;
      $result['roles_after']  = $after;
    } else {
      $result['error'] = 'invalid_user_id';
    }
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('simulate_result', $result));
    }
    wp_redirect(admin_url('admin.php?page=wpmps-subscribers'));
    exit;
  }

  public static function handle_reprocess(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wpmps_reprocess');
    $pre_id = sanitize_text_field($_GET['preapproval_id'] ?? '');
    if (function_exists('wpmps_log') && function_exists('wpmps_collect_context')){
      wpmps_log('DEBUG', wpmps_collect_context('admin_action', ['action'=>'wpmps_reprocess','preapproval_id'=>$pre_id]));
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
}
