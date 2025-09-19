<?php
if (!defined('ABSPATH')) exit;

// Mantener la página de opciones pero redirigir 100% al menú del plugin
add_action('admin_menu', function(){
  // Usamos un slug distinto para evitar colisión con el submenú del plugin
  add_options_page(
    __('WPMPS Ajustes', 'wp-mp-subscriptions'),
    __('WPMPS', 'wp-mp-subscriptions'),
    'manage_options',
    'wpmps-settings-redirect',
    'wpmps_render_settings_page'
  );
});

function wpmps_render_settings_page(){
  if (!current_user_can('manage_options')) return;
  // Redirigir a la vista dentro del menú del plugin (slug sin colisión)
  wp_safe_redirect(admin_url('admin.php?page=wpmps-settings'));
  exit;
}

// Render reutilizable para la nueva vista en el menú del plugin
function wpmps_render_settings_inner(){
  if (!current_user_can('manage_options')) return;

  if (!function_exists('get_editable_roles')) {
    $user_file = ABSPATH.'wp-admin/includes/user.php';
    if (file_exists($user_file)) {
      require_once $user_file;
    }
  }

  $webhook     = home_url('/wp-json/mp/v1/webhook');
  $const_token = (defined('MP_ACCESS_TOKEN') && !empty(MP_ACCESS_TOKEN)) ? MP_ACCESS_TOKEN : '';
  $opt_token   = get_option('wpmps_access_token', '');
  $roles       = function_exists('get_editable_roles') ? get_editable_roles() : [];
  $saved_role  = get_option('wpmps_role_on_authorized', '');
  if ($saved_role === 1 || $saved_role === '1') {
    $saved_role = 'suscriptor_premium';
  }
  if (!is_string($saved_role)) {
    $saved_role = '';
  }

  // Ping simple (sin exponer token)
  $saved = false;
  $pinged = false;
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpmps_save'])) {
    check_admin_referer('wpmps_save');
    if (empty($const_token)) { // Solo permite guardar si NO hay constante (constante prevalece)
      $new = isset($_POST['wpmps_access_token']) ? trim(wp_unslash($_POST['wpmps_access_token'])) : '';
      update_option('wpmps_access_token', $new, false);
      $opt_token = $new;
    }

    if (isset($_POST['wpmps_mp_domain'])){
      $dom = sanitize_text_field(wp_unslash($_POST['wpmps_mp_domain']));
      $dom = preg_replace('/[^a-z0-9\.-]/i', '', $dom);
      if ($dom === '') $dom = 'mercadopago.com.ar';
      update_option('wpmps_mp_domain', $dom, false);
    }

    $role_choice = isset($_POST['wpmps_role_on_authorized']) ? sanitize_text_field(wp_unslash($_POST['wpmps_role_on_authorized'])) : '';
    if ($role_choice !== '' && !isset($roles[$role_choice])) {
      $role_choice = '';
    }
    update_option('wpmps_role_on_authorized', $role_choice, false);
    $saved_role = $role_choice;

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

  echo '<form method="post" action="">';
  echo '<table class="form-table" role="presentation">';

  $mp_domain = get_option('wpmps_mp_domain', 'mercadopago.com.ar');
  echo '<tr><th>'.esc_html__('Dominio de MP (Link)', 'wp-mp-subscriptions').'</th><td>';
  echo '<input type="text" name="wpmps_mp_domain" class="regular-text" value="'.esc_attr($mp_domain).'" placeholder="mercadopago.com.ar" />';
  echo '<p class="description">'.esc_html__('Sólo para modo Link: dominio base del checkout de suscripciones. Ej: mercadopago.com.ar', 'wp-mp-subscriptions').'</p>';
  echo '</td></tr>';

  echo '<tr><th>'.esc_html__('URL de Webhook', 'wp-mp-subscriptions').'</th><td>';
  echo '<input type="text" id="wpmps_webhook" class="regular-text" readonly value="'.esc_attr($webhook).'" /> ';
  echo '<button class="button" type="button" id="wpmps_copy">'.esc_html__('Copiar', 'wp-mp-subscriptions').'</button>';
  echo '</td></tr>';

  echo '<tr><th>'.esc_html__('Access Token de Mercado Pago', 'wp-mp-subscriptions').'</th><td>';
  $token_disabled = !empty($const_token) ? 'disabled' : '';
  $placeholder = !empty($const_token) ? esc_attr__('Definido por wp-config.php (constante MP_ACCESS_TOKEN)', 'wp-mp-subscriptions') : esc_attr__('Pega aquí tu Access Token de MP', 'wp-mp-subscriptions');
  $value = !empty($const_token) ? $const_token : $opt_token;
  $masked = $value ? str_repeat('•', max(4, strlen($value)-4)).substr($value, -4) : '';
  echo '<div class="wpmps-token-wrap">';
  echo '<input type="password" id="wpmps_token" name="wpmps_access_token" class="regular-text" '.$token_disabled.' placeholder="'.$placeholder.'" value="'.esc_attr($opt_token).'" />';
  $toggle_disabled = !empty($const_token) ? 'disabled' : '';
  echo '<button type="button" class="button wpmps-token-toggle" id="wpmps_toggle_token" '.$toggle_disabled.' aria-label="'.esc_attr__('Mostrar u ocultar el token', 'wp-mp-subscriptions').'">';
  echo '<span class="dashicons dashicons-visibility" aria-hidden="true"></span>';
  echo '</button>';
  echo '</div>';
  if (!empty($const_token)) {
    echo '<p class="description">'.esc_html__('Actualmente se usa el token definido por constante (tiene prioridad).', 'wp-mp-subscriptions').' '.esc_html__('Valor (oculto):', 'wp-mp-subscriptions').' '.esc_html($masked).'</p>';
  }
  echo '</td></tr>';

  echo '<tr><th>'.esc_html__('Rol asignado al usuario cuando se confirme la suscripción', 'wp-mp-subscriptions').'</th><td>';
  echo '<select id="wpmps_role_on_authorized" name="wpmps_role_on_authorized" class="regular-text">';
  echo '<option value="">'.esc_html__('No cambiar el rol actual', 'wp-mp-subscriptions').'</option>';
  foreach ($roles as $slug => $details) {
    $selected = selected($saved_role, $slug, false);
    $name = isset($details['name']) ? $details['name'] : $slug;
    echo '<option value="'.esc_attr($slug).'" '.$selected.'>'.esc_html($name).'</option>';
  }
  echo '</select>';
  echo '</td></tr>';

  echo '</table>';

  wp_nonce_field('wpmps_save');
  echo '<p><button type="submit" name="wpmps_save" class="button button-primary">'.esc_html__('Guardar', 'wp-mp-subscriptions').'</button></p>';
  echo '</form>';

  $ping_url = wp_nonce_url(admin_url('admin.php?page=wpmps-settings&wpmps_ping=1'), 'wpmps_ping');
  echo '<p><a href="'.esc_url($ping_url).'" class="button button-secondary">'.esc_html__('Probar conexión', 'wp-mp-subscriptions').'</a></p>';

  echo '<script>
    (function(){
      var copyBtn = document.getElementById("wpmps_copy");
      if (copyBtn){
        copyBtn.addEventListener("click", function(){
          var inp = document.getElementById("wpmps_webhook");
          if (!inp) return;
          inp.select();
          inp.setSelectionRange(0, 99999);
          try { document.execCommand("copy"); } catch(e) {}
        });
      }

      var toggleBtn = document.getElementById("wpmps_toggle_token");
      if (toggleBtn && !toggleBtn.disabled){
        toggleBtn.addEventListener("click", function(){
          var inp = document.getElementById("wpmps_token");
          if (!inp) return;
          var isPassword = inp.getAttribute("type") === "password";
          inp.setAttribute("type", isPassword ? "text" : "password");
          var icon = toggleBtn.querySelector(".dashicons");
          if (icon){
            icon.classList.toggle("dashicons-visibility", !isPassword);
            icon.classList.toggle("dashicons-hidden", isPassword);
          }
        });
      }
    })();
  </script>';

  echo '</div>';
}
