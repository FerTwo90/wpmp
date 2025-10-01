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
    wpmps_log_auth('required', ['plan_id' => $plan_id, 'redirect_url' => wpmps_current_url()]);
    $cta_text = get_option('wpmps_login_cta_text', __('Iniciá sesión para suscribirte','wp-mp-subscriptions'));
    $login_url = wp_login_url(wpmps_current_url());
    return '<a class="'.esc_attr($class).'" href="'.esc_url($login_url).'">'.esc_html($cta_text).'</a>';
  }

  // User present: log and build Mercado Pago link
  wpmps_log_button('render', ['plan_id' => $plan_id, 'user_logged' => true]);

  if (empty($plan_id)){
    wpmps_log_error('shortcode', 'missing_plan', 'Plan ID is required for subscription button');
    return '<span class="'.esc_attr($class).'">'.esc_html__('Falta plan_id','wp-mp-subscriptions').'</span>';
  }

  $normalized = function_exists('wpmps_extract_plan_id') ? wpmps_extract_plan_id($plan_id) : $plan_id;
  $checkout = function_exists('wpmps_mp_checkout_url') ? wpmps_mp_checkout_url($normalized) : '';
  wpmps_log_button('link_generated', [
    'plan_id_raw'   => $plan_id,
    'plan_id'       => $normalized,
    'checkout_url'  => $checkout,
  ]);

  $attrs = ' class="'.esc_attr($class).'"';
  $html = '<a href="'.esc_url($checkout).'"'.$attrs.'>'.esc_html($label).'</a>';

  return $html;
}
