<?php if (!defined('ABSPATH')) exit; ?>
<p>
  <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_sync_plans'), 'wpmps_sync_plans')); ?>" class="button"><?php echo esc_html__('Sincronizar ahora', 'wp-mp-subscriptions'); ?></a>
</p>

<table class="widefat fixed striped">
    <thead><tr>
      <th><?php _e('Nombre','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Plan ID','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Monto','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Frecuencia','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Estado','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Acciones','wp-mp-subscriptions'); ?></th>
    </tr></thead>
    <tbody>
      <?php if (empty($plans)): ?>
        <tr><td colspan="6"><?php _e('Sin planes disponibles o token inválido.', 'wp-mp-subscriptions'); ?></td></tr>
      <?php else: foreach ($plans as $p): ?>
        <tr>
          <td><?php echo esc_html($p['name'] ?? ''); ?></td>
          <td><code><?php echo esc_html($p['id'] ?? ''); ?></code></td>
          <td><?php echo esc_html(isset($p['amount']) ? $p['amount'] : ''); ?></td>
          <td><?php echo esc_html($p['frequency'] ?? ''); ?></td>
          <td><?php echo esc_html($p['status'] ?? ''); ?></td>
          <td>
            <?php
              $plan_id = isset($p['id']) ? sanitize_text_field($p['id']) : '';
              $label_raw = isset($p['name']) ? $p['name'] : __('Suscribirme','wp-mp-subscriptions');
              $label = sanitize_text_field($label_raw);
              $sc = '[mp_subscribe plan_id="'.$plan_id.'" label="'.$label.'"]';
            ?>
            <button type="button" class="button wpmps-copy" data-sc="<?php echo esc_attr($sc); ?>"><?php _e('Copiar shortcode','wp-mp-subscriptions'); ?></button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

<script>
(function(){
  document.querySelectorAll('.wpmps-copy').forEach(function(btn){
    btn.addEventListener('click', function(){
      navigator.clipboard && navigator.clipboard.writeText(btn.dataset.sc);
    });
  });
})();
</script>
