<?php
if (!defined('ABSPATH')) exit;

// Register Gutenberg block (server) and shortcode
add_action('init', function(){
  // Shortcode registration
  add_shortcode('mp_subscribe', 'wpmps_render_subscribe_shortcode');

  // Register block if metadata exists
  if (function_exists('register_block_type_from_metadata') && defined('WPMPS_DIR')){
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
  // Verificar que las funciones necesarias están disponibles
  if (!function_exists('wpmps_current_url') || !function_exists('wpmps_log_button')) {
    return '<span style="color: red;">[Error: Plugin functions not loaded]</span>';
  }

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
    if (function_exists('wpmps_log_auth')) {
      wpmps_log_auth('required', ['plan_id' => $plan_id, 'redirect_url' => wpmps_current_url()]);
    }
    $cta_text = get_option('wpmps_login_cta_text', __('Iniciá sesión para suscribirte','wp-mp-subscriptions'));
    $login_url = wp_login_url(wpmps_current_url());
    return '<a class="'.esc_attr($class).'" href="'.esc_url($login_url).'">'.esc_html($cta_text).'</a>';
  }

  // User present: log and build Mercado Pago link
  if (function_exists('wpmps_log_button')) {
    wpmps_log_button('render', ['plan_id' => $plan_id, 'user_logged' => true]);
  }

  if (empty($plan_id)){
    if (function_exists('wpmps_log_error')) {
      wpmps_log_error('shortcode', 'missing_plan', 'Plan ID is required for subscription button');
    }
    return '<span class="'.esc_attr($class).'">'.esc_html__('Falta plan_id','wp-mp-subscriptions').'</span>';
  }

  $normalized = function_exists('wpmps_extract_plan_id') ? wpmps_extract_plan_id($plan_id) : $plan_id;
  $checkout = function_exists('wpmps_mp_checkout_url') ? wpmps_mp_checkout_url($normalized) : '';
  
  if (function_exists('wpmps_log_button')) {
    wpmps_log_button('link_generated', [
      'plan_id_raw'   => $plan_id,
      'plan_id'       => $normalized,
      'checkout_url'  => $checkout,
    ]);
  }

  // Generar ID único para este shortcode
  $unique_id = 'wpmps_' . uniqid();
  
  // HTML con botón y formulario para número de operación
  $html = '<div class="wpmps-subscription-widget" id="' . $unique_id . '">';
  $html .= '<a href="'.esc_url($checkout).'" class="'.esc_attr($class).'" target="_blank" rel="noopener noreferrer">'.esc_html($label).'</a>';
  
  // Formulario para ingresar número de operación manualmente
  $html .= '<div class="wpmps-manual-payment" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">';
  $html .= '<p style="margin: 0 0 10px 0; font-size: 14px;"><strong>' . __('¿Ya pagaste pero no se registró automáticamente?', 'wp-mp-subscriptions') . '</strong></p>';
  $html .= '<p style="margin: 0 0 15px 0; font-size: 12px; color: #666;">' . __('Completa los datos que recibiste por email para validar tu pago:', 'wp-mp-subscriptions') . '</p>';
  
  $html .= '<form class="wpmps-payment-form" style="display: grid; gap: 10px; grid-template-columns: 1fr 1fr; align-items: end;">';
  
  $html .= '<div>';
  $html .= '<label style="display: block; font-size: 12px; margin-bottom: 5px;">' . __('CUIT/CUIL del medio de pago:', 'wp-mp-subscriptions') . '</label>';
  $html .= '<input type="text" name="cuit_cuil" placeholder="' . __('Ej: 20123456789', 'wp-mp-subscriptions') . '" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px;" required />';
  $html .= '</div>';
  
  $html .= '<div>';
  $html .= '<label style="display: block; font-size: 12px; margin-bottom: 5px;">' . __('Número de operación:', 'wp-mp-subscriptions') . '</label>';
  $html .= '<input type="text" name="payment_id" placeholder="' . __('Ej: 1234567890', 'wp-mp-subscriptions') . '" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px;" required />';
  $html .= '</div>';
  
  $html .= '<input type="hidden" name="plan_id" value="' . esc_attr($normalized) . '" />';
  $html .= '<input type="hidden" name="action" value="wpmps_register_manual_payment" />';
  $html .= '<input type="hidden" name="nonce" value="' . wp_create_nonce('wpmps_manual_payment') . '" />';
  
  $html .= '<div style="grid-column: 1 / -1; text-align: center; margin-top: 10px;">';
  $html .= '<button type="submit" class="button button-primary">' . __('Validar y Registrar Pago', 'wp-mp-subscriptions') . '</button>';
  $html .= '</div>';
  $html .= '</form>';
  
  $html .= '<div class="wpmps-result" style="margin-top: 10px;"></div>';
  $html .= '</div>';
  $html .= '</div>';

  // JavaScript para manejar el formulario
  $html .= '<script>
  document.addEventListener("DOMContentLoaded", function() {
    const form = document.querySelector("#' . $unique_id . ' .wpmps-payment-form");
    const resultDiv = document.querySelector("#' . $unique_id . ' .wmpps-result");
    
    if (form) {
      form.addEventListener("submit", function(e) {
        e.preventDefault();
        
        const formData = new FormData(form);
        const button = form.querySelector("button[type=submit]");
        const originalText = button.textContent;
        
        button.disabled = true;
        button.textContent = "' . __('Verificando...', 'wp-mp-subscriptions') . '";
        
        fetch("' . admin_url('admin-ajax.php') . '", {
          method: "POST",
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          const resultDiv = document.querySelector("#' . $unique_id . ' .wpmps-result");
          if (data.success) {
            resultDiv.innerHTML = "<div style=\"color: #00a32a; font-weight: bold; padding: 10px; background: #f0f8f0; border: 1px solid #00a32a; border-radius: 3px;\">" + data.data + "<br><small>' . __('Redirigiendo a cartelera...', 'wp-mp-subscriptions') . '</small></div>";
            form.reset();
            // Redirigir a cartelera después de 2 segundos
            setTimeout(() => {
              window.location.href = "' . home_url('/cartelera') . '";
            }, 2000);
          } else {
            resultDiv.innerHTML = "<div style=\"color: #d63638; font-weight: bold; padding: 10px; background: #ffeaea; border: 1px solid #d63638; border-radius: 3px;\">" + data.data + "</div>";
          }
        })
        .catch(error => {
          const resultDiv = document.querySelector("#' . $unique_id . ' .wpmps-result");
          resultDiv.innerHTML = "<div style=\"color: #d63638; font-weight: bold; padding: 10px; background: #ffeaea; border: 1px solid #d63638; border-radius: 3px;\">' . __('Error al procesar la solicitud.', 'wp-mp-subscriptions') . '</div>";
        })
        .finally(() => {
          button.disabled = false;
          button.textContent = originalText;
        });
      });
    }
  });
  </script>';

  return $html;
}

// Simple redirect approach - no complex modal functionality needed
