<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php echo esc_html__('Suscriptores', 'wp-mp-subscriptions'); ?></h1>
  <p>
    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_export_csv'), 'wpmps_export_csv')); ?>" class="button">CSV</a>
  </p>
  <table class="widefat fixed striped">
    <thead><tr>
      <th><?php _e('Email','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Usuario','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Preapproval ID','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Plan ID','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Estado','wp-mp-subscriptions'); ?></th>
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
          <td><code><?php echo esc_html($s['plan_id']); ?></code></td>
          <td><?php echo esc_html($s['status']); ?></td>
          <td><?php echo esc_html($s['updated_at']); ?></td>
          <td>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_refresh_sub&user_id='.$s['user_id']), 'wpmps_refresh_sub') ); ?>"><?php _e('Refrescar estado','wp-mp-subscriptions'); ?></a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

