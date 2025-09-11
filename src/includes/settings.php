<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function(){
  add_options_page(
    __('WPMPS Ajustes', 'wp-mp-subscriptions'),
    __('WPMPS', 'wp-mp-subscriptions'),
    'manage_options',
    'wpmps-settings',
    'wpmps_render_settings_page'
  );
});

function wpmps_render_settings_page(){
  if (!current_user_can('manage_options')) return;

  $webhook = home_url('/wp-json/mp/v1/webhook');
  $const_token = (defined('MP_ACCESS_TOKEN') && !empty(MP_ACCESS_TOKEN)) ? MP_ACCESS_TOKEN : '';
  $opt_token   = get_option('wpmps_access_token', '');
  $effective   = $const_token ?: $opt_token;
  $has_token   = !empty($effective);

  // Ping simple (sin exponer token)
  $saved = false;
  $pinged = false;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpmps_save'])) {
    check_admin_referer('wpmps_save');
    if (empty($const_token)) { // Solo permite guardar si NO hay constante (constante prevalece)
      $new = isset($_POST['wpmps_access_token']) ? trim(wp_unslash($_POST['wpmps_access_token'])) : '';
      update_option('wpmps_access_token', $new, false);
      $opt_token = $new;
      $effective = $opt_token;
      $has_token = !empty($effective);
    }
    if (isset($_POST['wpmps_default_plan_id'])) {
      $pid = sanitize_text_field(wp_unslash($_POST['wpmps_default_plan_id']));
      update_option('wpmps_default_plan_id', $pid, false);
    }
    $saved = true;
  }
  if (isset($_GET['wpmps_ping']) && check_admin_referer('wpmps_ping')) {
    $pinged = true;
    echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Ping OK', 'wp-mp-subscriptions').'</p></div>';
  }

  echo '<div class="wrap">';
  echo '<h1>'.esc_html__('WP MP Subscriptions — Ajustes', 'wp-mp-subscriptions').'</h1>';

  if ($saved) {
    echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Ajustes guardados.', 'wp-mp-subscriptions').'</p></div>';
  }

  // Abrimos el formulario antes de la tabla para que el input se envíe
  echo '<form method="post" action="">';
  echo '<table class="form-table" role="presentation">';
  echo '<tr><th>'.esc_html__('URL de Webhook', 'wp-mp-subscriptions').'</th><td>';
  echo '<input type="text" id="wpmps_webhook" class="regular-text" readonly value="'.esc_attr($webhook).'" /> ';
  echo '<button class="button" type="button" id="wpmps_copy">'.esc_html__('Copiar', 'wp-mp-subscriptions').'</button>';
  echo '</td></tr>';

  // Plan ID por defecto
  $default_plan = get_option('wpmps_default_plan_id', '');
  echo '<tr><th>'.esc_html__('Plan ID por defecto', 'wp-mp-subscriptions').'</th><td>';
  echo '<input type="text" name="wpmps_default_plan_id" class="regular-text" value="'.esc_attr($default_plan).'" placeholder="preapproval_plan_id" />';
  echo '<p class="description">'.esc_html__('Si se completa, el shortcode usará este Plan ID salvo que se pase plan_id="...".', 'wp-mp-subscriptions').'</p>';
  echo '</td></tr>';

  echo '<tr><th>'.esc_html__('Token detectado', 'wp-mp-subscriptions').'</th><td>';
  if ($has_token) {
    echo '<span style="color: #0a0;">✅ ' . esc_html__('Sí', 'wp-mp-subscriptions') . '</span>';
  } else {
    echo '<span style="color: #a00;">❌ ' . esc_html__('No', 'wp-mp-subscriptions') . '</span>';
  }
  echo '</td></tr>';

  echo '<tr><th>'.esc_html__('Access Token', 'wp-mp-subscriptions').'</th><td>';
  $disabled = !empty($const_token) ? 'disabled' : '';
  $placeholder = !empty($const_token) ? esc_attr__('Definido por wp-config.php (constante MP_ACCESS_TOKEN)', 'wp-mp-subscriptions') : esc_attr__('Pega aquí tu Access Token de MP', 'wp-mp-subscriptions');
  $value = !empty($const_token) ? $const_token : $opt_token;
  $masked = $value ? str_repeat('•', max(4, strlen($value)-4)).substr($value, -4) : '';
  echo '<input type="password" id="wpmps_token" name="wpmps_access_token" class="regular-text" '.$disabled.' placeholder="'.$placeholder.'" value="'.esc_attr($opt_token).'" />';
  if (!empty($const_token)) {
    echo '<p class="description">'.esc_html__('Actualmente se usa el token definido por constante (tiene prioridad).', 'wp-mp-subscriptions').' '.esc_html__('Valor (oculto):', 'wp-mp-subscriptions').' '.esc_html($masked).'</p>';
  }
  echo '</td></tr>';

  echo '</table>';
  wp_nonce_field('wpmps_save');
  echo '<p><button type="submit" name="wpmps_save" class="button button-primary" '.(!empty($const_token)?'disabled':'').'>'.esc_html__('Guardar', 'wp-mp-subscriptions').'</button></p>';
  echo '</form>';

  $ping_url = wp_nonce_url(admin_url('options-general.php?page=wpmps-settings&wpmps_ping=1'), 'wpmps_ping');
  echo '<p><a href="'.esc_url($ping_url).'" class="button button-secondary">'.esc_html__('Probar conexión', 'wp-mp-subscriptions').'</a></p>';

  echo '<script>
    (function(){
      var btn = document.getElementById("wpmps_copy");
      if (!btn) return;
      btn.addEventListener("click", function(){
        var inp = document.getElementById("wpmps_webhook");
        inp.select();
        inp.setSelectionRange(0, 99999);
        try { document.execCommand("copy"); } catch(e) {}
      });
    })();
  </script>';

  echo '</div>';
}
