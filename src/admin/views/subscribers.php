<?php if (!defined('ABSPATH')) exit; ?>

<div class="card" style="margin:10px 0;padding:12px;max-width:820px;">
  <h2 style="margin-top:0;"><?php _e('Usuario de prueba', 'wp-mp-subscriptions'); ?></h2>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="wpmps_simulate_sub" />
    <?php wp_nonce_field('wpmps_simulate_sub'); ?>
    <table class="form-table"><tbody>
      <tr>
        <th><label for="wpmps_user_id"><?php _e('User ID','wp-mp-subscriptions'); ?></label></th>
        <td><input type="number" min="1" class="small-text" id="wpmps_user_id" name="user_id" value="316" />
          <p class="description"><?php _e('ID del usuario WP a simular', 'wp-mp-subscriptions'); ?></p>
        </td>
      </tr>
      <tr>
        <th><label for="wpmps_role"><?php _e('Rol a aplicar','wp-mp-subscriptions'); ?></label></th>
        <td>
          <select id="wpmps_role" name="role">
            <option value=""><?php _e('— ninguno —','wp-mp-subscriptions'); ?></option>
            <?php
              if (function_exists('wp_roles')){
                $roles = wp_roles()->roles;
                foreach ($roles as $slug=>$r){
                  echo '<option value="'.esc_attr($slug).'">'.esc_html($r['name'].' ('.$slug.')').'</option>';
                }
              }
            ?>
          </select>
          <p class="description"><?php _e('Se agrega cuando el estado sea authorized y se quita en otros estados.', 'wp-mp-subscriptions'); ?></p>
        </td>
      </tr>
      
    </tbody></table>
    <p><button class="button button-primary" type="submit"><?php _e('Aplicar simulación','wp-mp-subscriptions'); ?></button></p>
  </form>
</div>

<p>
  <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_export_csv'), 'wpmps_export_csv')); ?>" class="button">CSV</a>
  <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_refresh_all'), 'wpmps_refresh_all')); ?>" class="button"><?php _e('Refrescar todos','wp-mp-subscriptions'); ?></a>
</p>
  <table class="widefat fixed striped">
    <thead><tr>
      <th><?php _e('Email','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Usuario','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Preapproval ID','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Plan','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Motivo','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Monto','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Frecuencia','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Estado','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Creado','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Actualizado','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Acciones','wp-mp-subscriptions'); ?></th>
    </tr></thead>
    <tbody>
      <?php if (empty($subs)): ?>
        <tr><td colspan="7"><?php _e('Sin suscriptores aún.', 'wp-mp-subscriptions'); ?></td></tr>
      <?php else: foreach ($subs as $s): ?>
        <tr>
          <td><?php echo esc_html($s['email']); ?></td>
          <td><?php echo $s['user_id'] ? '<a href="'.esc_url(get_edit_user_link($s['user_id'])).'">'.intval($s['user_id']).'</a>' : '-'; ?></td>
          <td><code><?php echo esc_html($s['preapproval_id']); ?></code></td>
          <td>
            <?php
              $pid = $s['plan_id'];
              if (!empty($pid)){
                $name = isset($plans_map[$pid]) ? $plans_map[$pid] : '';
                echo $name ? esc_html($name).' — ' : '';
                echo '<code>'.esc_html($pid).'</code>';
              } else { echo '-'; }
            ?>
          </td>
          <td><?php echo esc_html($s['reason'] ?? ''); ?></td>
          <td><?php echo isset($s['amount']) && $s['amount']!=='' ? esc_html(number_format((float)$s['amount'], 2, ',', '.').' '.$s['currency']) : '-'; ?></td>
          <td><?php echo (isset($s['frequency']) && $s['frequency']!=='') ? esc_html($s['frequency'].'/'.$s['frequency_type']) : '-'; ?></td>
          <td><?php echo esc_html($s['status']); ?></td>
          <td><?php echo esc_html($s['date_created'] ?? ''); ?></td>
          <td><?php echo esc_html($s['updated_at']); ?></td>
          <td>
            <?php if (!empty($s['user_id'])): ?>
              <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_refresh_sub&user_id='.$s['user_id']), 'wpmps_refresh_sub') ); ?>"><?php _e('Refrescar estado','wp-mp-subscriptions'); ?></a>
            <?php endif; ?>
            <?php if (!empty($s['preapproval_id'])): ?>
              <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_reprocess&preapproval_id='.$s['preapproval_id']), 'wpmps_reprocess') ); ?>"><?php _e('Reprocesar','wp-mp-subscriptions'); ?></a>
            <?php endif; ?>
            <?php if (!empty($s['init_point'])): ?>
              <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($s['init_point']); ?>"><?php _e('Checkout','wp-mp-subscriptions'); ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
