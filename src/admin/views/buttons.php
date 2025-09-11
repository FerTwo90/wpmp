<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php echo esc_html__('Generador de Botón', 'wp-mp-subscriptions'); ?></h1>

  <table class="form-table">
    <tr>
      <th><?php _e('Plan','wp-mp-subscriptions'); ?></th>
      <td>
        <select id="wpmps_plan">
          <option value="">— <?php _e('Seleccionar','wp-mp-subscriptions'); ?> —</option>
          <?php foreach ($plans as $p): ?>
            <option value="<?php echo esc_attr($p['id'] ?? ''); ?>"><?php echo esc_html(($p['name'] ?? '').' — '.($p['id'] ?? '')); ?></option>
          <?php endforeach; ?>
        </select>
      </td>
    </tr>
    <tr>
      <th><?php _e('Label','wp-mp-subscriptions'); ?></th>
      <td><input type="text" id="wpmps_label" class="regular-text" value="<?php echo esc_attr__('Suscribirme','wp-mp-subscriptions'); ?>" /></td>
    </tr>
    <tr>
      <th><?php _e('Back URL','wp-mp-subscriptions'); ?></th>
      <td><input type="text" id="wpmps_back" class="regular-text" value="/resultado-suscripcion" /></td>
    </tr>
  </table>

  <p>
    <button class="button button-primary" id="wpmps_preview"><?php _e('Previsualizar','wp-mp-subscriptions'); ?></button>
    <button class="button" id="wpmps_copy"><?php _e('Copiar shortcode','wp-mp-subscriptions'); ?></button>
    <?php if ($has_blocks): ?>
      <a class="button" id="wpmps_insert" href="#"><?php _e('Insertar en página','wp-mp-subscriptions'); ?></a>
    <?php endif; ?>
  </p>

  <p><code id="wpmps_sc"></code></p>

  <script>
    (function(){
      function build(){
        var pid = document.getElementById('wpmps_plan').value.trim();
        var label = document.getElementById('wpmps_label').value.trim();
        var back = document.getElementById('wpmps_back').value.trim();
        var sc = '[mp_subscribe plan_id="'+pid+'" reason="'+(label||'Suscripción')+'" back="'+back+'"]';
        document.getElementById('wpmps_sc').textContent = sc; return sc;
      }
      document.getElementById('wpmps_preview').addEventListener('click', build);
      document.getElementById('wpmps_copy').addEventListener('click', function(){ navigator.clipboard && navigator.clipboard.writeText(build()); });
      var insert = document.getElementById('wpmps_insert');
      if (insert){
        insert.addEventListener('click', function(e){ e.preventDefault(); var sc=build(); window.location = '<?php echo esc_js(admin_url('post-new.php?post_type=page&_wpmps_shortcode=')); ?>'+encodeURIComponent(sc); });
      }
    })();
  </script>
</div>

