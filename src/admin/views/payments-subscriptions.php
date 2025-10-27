<?php if (!defined('ABSPATH')) exit; ?>

<?php
$payments_data       = isset($payments_data) ? $payments_data : ['success' => false, 'payments' => []];
$subscriptions_data  = isset($subscriptions_data) ? $subscriptions_data : ['success' => false, 'subscriptions' => []];
$payments            = isset($payments_data['payments']) ? $payments_data['payments'] : [];
$subscriptions       = isset($subscriptions_data['subscriptions']) ? $subscriptions_data['subscriptions'] : [];
$payments_total      = isset($payments_data['total']) ? intval($payments_data['total']) : count($payments);
$subscriptions_total = isset($subscriptions_data['total']) ? intval($subscriptions_data['total']) : count($subscriptions);
$seed_result         = isset($seed_result) ? $seed_result : ['seeded'=>false,'message'=>''];
$filters             = isset($filters) ? $filters : [];

$payments_by_preapproval = [];
foreach ($payments as $payment){
  $key = !empty($payment['preapproval_id'])
    ? $payment['preapproval_id']
    : ('payment:'.$payment['payment_id']);
  $payments_by_preapproval[$key] = $payment;
}
?>

<?php if (!empty($seed_result['message'])): ?>
  <div class="notice notice-<?php echo $seed_result['seeded'] ? 'success' : 'info'; ?> is-dismissible" style="margin-top:15px;">
    <p><?php echo esc_html($seed_result['message']); ?></p>
  </div>
<?php endif; ?>

<?php if (!$payments_data['success'] || !$subscriptions_data['success']): ?>
  <div class="notice notice-warning" style="margin-top: 15px;">
    <p><?php _e('Aún no hay datos en la tabla de mapeo. Una vez que vuelques pagos/suscripciones desde Mercado Pago, aparecerán acá.', 'wp-mp-subscriptions'); ?></p>
  </div>
<?php endif; ?>

<div style="display:flex; flex-wrap:wrap; gap:20px; margin-top:20px;">
  <div style="flex:1; min-width:260px; background:#f9f9f9; border:1px solid #dcdcdc; padding:20px;">
    <h2 style="margin-top:0;"><?php _e('Resumen rápido', 'wp-mp-subscriptions'); ?></h2>
    <p><strong><?php _e('Pagos almacenados:', 'wp-mp-subscriptions'); ?></strong> <?php echo esc_html(number_format_i18n($payments_total)); ?></p>
    <p><strong><?php _e('Suscripciones almacenadas:', 'wp-mp-subscriptions'); ?></strong> <?php echo esc_html(number_format_i18n($subscriptions_total)); ?></p>
    <p style="font-size:12px; color:#666;">
      <?php printf(esc_html__('Mostrando hasta %1$d filas (limit %2$d, offset %3$d)', 'wp-mp-subscriptions'),
        max(count($subscriptions), count($payments)),
        isset($subscriptions_data['limit']) ? intval($subscriptions_data['limit']) : 25,
        isset($subscriptions_data['offset']) ? intval($subscriptions_data['offset']) : 0
      ); ?>
    </p>
    <?php if (!empty($filters)): ?>
      <p style="font-size:12px; color:#666; margin-top:10px;"><?php _e('Filtros aplicados:', 'wp-mp-subscriptions'); ?> <code><?php echo esc_html(wp_json_encode($filters)); ?></code></p>
    <?php endif; ?>
  </div>
</div>

<div style="margin-top:30px;">
  <h2><?php _e('Mapa de Pagos y Suscripciones', 'wp-mp-subscriptions'); ?></h2>
  <table class="widefat fixed striped">
    <thead>
      <tr>
        <th><?php _e('Preapproval ID', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Plan', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Usuario WP', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Estado suscripción', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Fecha suscripción', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Payment ID', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Monto', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Estado pago', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Fecha pago', 'wp-mp-subscriptions'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php
      if (empty($subscriptions) && empty($payments)) {
        echo '<tr><td colspan="9" style="text-align:center;">'.esc_html__('Aún no hay registros en la tabla de mapeo.', 'wp-mp-subscriptions').'</td></tr>';
      } else {
        $matched_keys = [];
        foreach ($subscriptions as $sub) {
          $key = !empty($sub['preapproval_id'])
            ? $sub['preapproval_id']
            : ('plan:'.$sub['plan_id']);
          $payment = isset($payments_by_preapproval[$key]) ? $payments_by_preapproval[$key] : null;
          if ($payment) {
            $matched_keys[] = $key;
          }
          ?>
          <tr>
            <td><code><?php echo esc_html($sub['preapproval_id'] ?? '—'); ?></code></td>
            <td><code><?php echo esc_html($sub['plan_id'] ?? '—'); ?></code></td>
            <td><?php echo !empty($sub['user_id']) ? '<a href="'.esc_url(get_edit_user_link(intval($sub['user_id']))).'">'.intval($sub['user_id']).'</a>' : '—'; ?></td>
            <td><?php echo esc_html($sub['status'] ?? '—'); ?></td>
            <td><code><?php echo esc_html($sub['created_at'] ?? '—'); ?></code></td>
            <td><code><?php echo esc_html($payment['payment_id'] ?? '—'); ?></code></td>
            <td><?php echo esc_html(isset($payment['amount']) ? $payment['amount'].' '.($payment['currency'] ?? '') : '—'); ?></td>
            <td><?php echo esc_html($payment['status'] ?? '—'); ?></td>
            <td><code><?php echo esc_html($payment['created_at'] ?? '—'); ?></code></td>
          </tr>
          <?php
        }

        foreach ($payments_by_preapproval as $key => $payment) {
          if (in_array($key, $matched_keys, true)) continue;
          ?>
          <tr>
            <td><code><?php echo esc_html($payment['preapproval_id'] ?? '—'); ?></code></td>
            <td><code><?php echo esc_html($payment['plan_id'] ?? '—'); ?></code></td>
            <td>—</td>
            <td>—</td>
            <td>—</td>
            <td><code><?php echo esc_html($payment['payment_id'] ?? '—'); ?></code></td>
            <td><?php echo esc_html(isset($payment['amount']) ? $payment['amount'].' '.($payment['currency'] ?? '') : '—'); ?></td>
            <td><?php echo esc_html($payment['status'] ?? '—'); ?></td>
            <td><code><?php echo esc_html($payment['created_at'] ?? '—'); ?></code></td>
          </tr>
          <?php
        }
      }
      ?>
    </tbody>
  </table>
</div>
