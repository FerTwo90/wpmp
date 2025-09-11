<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php echo esc_html__('Logs / Webhooks', 'wp-mp-subscriptions'); ?></h1>
  <table class="widefat fixed striped">
    <thead><tr>
      <th><?php _e('Fecha','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Preapproval ID','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Estado','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Email','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Acciones','wp-mp-subscriptions'); ?></th>
    </tr></thead>
    <tbody>
      <?php if (empty($events)): ?>
        <tr><td colspan="5"><?php _e('Sin eventos aún.', 'wp-mp-subscriptions'); ?></td></tr>
      <?php else: foreach ($events as $e): ?>
        <tr>
          <td><?php echo esc_html($e['date'] ?? ''); ?></td>
          <td><code><?php echo esc_html($e['preapproval_id'] ?? ''); ?></code></td>
          <td><?php echo esc_html($e['status'] ?? ''); ?></td>
          <td><?php echo esc_html($e['email'] ?? ''); ?></td>
          <td>
            <?php if (!empty($e['preapproval_id'])): ?>
              <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_reprocess&preapproval_id='.$e['preapproval_id']), 'wpmps_reprocess') ); ?>"><?php _e('Reprocesar','wp-mp-subscriptions'); ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

