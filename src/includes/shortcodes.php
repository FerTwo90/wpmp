<?php
if (!defined('ABSPATH')) exit;

// Register Gutenberg block (server) and shortcode
add_action('init', function(){
  // Shortcode registration
  add_shortcode('mp_subscribe', 'wpmps_render_subscribe_shortcode');

  // Register block if metadata exists
  if (function_exists('register_block_type_from_metadata')){
    $meta_dir = WPMPS_DIR.'blocks/subscribe-button';
    if (file_exists($meta_dir.'/block.json')){
      register_block_type_from_metadata($meta_dir, [
        'render_callback' => function($attrs){
          $plan  = isset($attrs['plan_id']) ? sanitize_text_field($attrs['plan_id']) : '';
          $label = isset($attrs['label']) ? sanitize_text_field($attrs['label']) : __('Suscribirme','wp-mp-subscriptions');
          $class = isset($attrs['class']) ? sanitize_html_class($attrs['class']) : 'wp-mps-btn';
          $sc = '[mp_subscribe'
            .($plan?(' plan_id="'.esc_attr($plan).'"'):'')
            .' label="'.esc_attr($label).'"'
            .' class="'.esc_attr($class).'"'
            .']';
          return do_shortcode($sc);
        }
      ]);
    }
  }
});

// Shortcode implementation
function wpmps_render_subscribe_shortcode($atts){
  // Soft no-cache headers to avoid CDN caching of session-dependent content
  if (!headers_sent()) {
    nocache_headers();
  }

  $a = shortcode_atts([
    'plan_id' => '',
    'label'   => __('Suscribirme', 'wp-mp-subscriptions'),
    'class'   => 'wp-mps-btn',
  ], $atts, 'mp_subscribe');

  $plan_id = sanitize_text_field($a['plan_id']);
  if (!empty($plan_id) && function_exists('wpmps_extract_plan_id')) {
    $plan_id = wpmps_extract_plan_id($plan_id);
  }
  $label   = sanitize_text_field($a['label']);
  $class   = sanitize_html_class($a['class']);

  if (!is_user_logged_in()){
    $ctx = wpmps_collect_context('shortcode', ['state'=>'not_logged']);
    wpmps_log('USER', $ctx);
    $cta_text = get_option('wpmps_login_cta_text', __('Iniciá sesión para suscribirte','wp-mp-subscriptions'));
    $login_url = wp_login_url(wpmps_current_url());
    return '<a class="'.esc_attr($class).'" href="'.esc_url($login_url).'">'.esc_html($cta_text).'</a>';
  }

  // User present: log and build Mercado Pago link
  $ctx = wpmps_collect_context('shortcode', ['state'=>'logged','plan_id'=>$plan_id]);
  wpmps_log('USER', $ctx);

  if (empty($plan_id)){
    wpmps_log('ERROR', wpmps_collect_context('shortcode', ['reason'=>'missing_plan']));
    return '<span class="'.esc_attr($class).'">'.esc_html__('Falta plan_id','wp-mp-subscriptions').'</span>';
  }

  $normalized = function_exists('wpmps_extract_plan_id') ? wpmps_extract_plan_id($plan_id) : $plan_id;
  $checkout = function_exists('wpmps_mp_checkout_url') ? wpmps_mp_checkout_url($normalized) : '';
  wpmps_log('CREATE', wpmps_collect_context('link', [
    'plan_id_raw'   => $plan_id,
    'plan_id'       => $normalized,
    'checkout_url'  => $checkout,
  ]));

  $attrs = ' class="'.esc_attr($class).'"';
  $name = ' name="MP-payButton"';
  $html = '<a href="'.esc_url($checkout).'"'.$name.$attrs.'>'.esc_html($label).'</a>';

  static $render_loaded = false;
  if (!$render_loaded){
    $html .= '<script type="text/javascript">(function(){function l(){if(window.$MPC_loaded===true)return;var s=document.createElement("script");s.type="text/javascript";s.async=true;s.src=(("https:"==document.location.protocol)?"https":"http")+"://secure.mlstatic.com/mptools/render.js";var x=document.getElementsByTagName("script")[0];x.parentNode.insertBefore(s,x);window.$MPC_loaded=true;}if(window.$MPC_loaded!==true){if(window.attachEvent){window.attachEvent("onload",l);}else{window.addEventListener("load",l,false);}}})();</script>';
    $render_loaded = true;
  }

  return $html;
}
