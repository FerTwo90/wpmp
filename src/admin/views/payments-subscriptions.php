<?php if (!defined('ABSPATH')) exit; ?>

<?php
$payments_data         = isset($payments_data) ? $payments_data : ['success' => false, 'payments' => []];
$subscriptions_data    = isset($subscriptions_data) ? $subscriptions_data : ['success' => false, 'subscriptions' => []];
$payments              = isset($payments_data['payments']) ? $payments_data['payments'] : [];
$subscriptions         = isset($subscriptions_data['subscriptions']) ? $subscriptions_data['subscriptions'] : [];
$payments_total        = isset($payments_data['total']) ? intval($payments_data['total']) : count($payments);
$subscriptions_total   = isset($subscriptions_data['total']) ? intval($subscriptions_data['total']) : count($subscriptions);
$seed_result           = isset($seed_result) ? $seed_result : ['seeded'=>false,'message'=>''];
$payments_seed_result  = isset($payments_seed_result) ? $payments_seed_result : ['seeded'=>false,'message'=>''];
$filters               = isset($filters) ? $filters : [];

$payments_by_key = [];
foreach ($payments as $payment){
  $keys = [];
  if (!empty($payment['preapproval_id'])) {
    $keys[] = 'pre:'.$payment['preapproval_id'];
  }
  if (!empty($payment['payer_id'])) {
    $keys[] = 'payer:'.$payment['payer_id'];
  }
  if (empty($keys)) {
    $keys[] = 'payment:'.$payment['payment_id'];
  }
  foreach ($keys as $mapKey){
    $payments_by_key[$mapKey] = $payment;
  }
}
$matched_payment_ids = [];
?>

<?php if (!empty($seed_result['message'])): ?>
  <div class="notice notice-<?php echo $seed_result['seeded'] ? 'success' : 'info'; ?> is-dismissible" style="margin-top:15px;">
    <p><?php echo esc_html($seed_result['message']); ?></p>
  </div>
<?php endif; ?>

<?php if (!empty($payments_seed_result['message'])): ?>
  <div class="notice notice-<?php echo $payments_seed_result['seeded'] ? 'success' : 'info'; ?> is-dismissible" style="margin-top:15px;">
    <p><?php echo esc_html($payments_seed_result['message']); ?></p>
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
        <th><?php _e('Nombre', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Email', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Documento', 'wp-mp-subscriptions'); ?></th>
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
        foreach ($subscriptions as $sub) {
          $payment = null;
          if (!empty($sub['preapproval_id']) && isset($payments_by_key['pre:'.$sub['preapproval_id']])) {
            $payment = $payments_by_key['pre:'.$sub['preapproval_id']];
          } elseif (!empty($sub['payer_id']) && isset($payments_by_key['payer:'.$sub['payer_id']])) {
            $payment = $payments_by_key['payer:'.$sub['payer_id']];
          }
          if ($payment && !empty($payment['payment_id'])) {
            $matched_payment_ids[] = $payment['payment_id'];
          }
          $name = trim(($sub['payer_first_name'] ?? '').' '.($sub['payer_last_name'] ?? ''));
          if (!$name && $payment) {
            $name = trim(($payment['payer_first_name'] ?? '').' '.($payment['payer_last_name'] ?? ''));
          }
          $email = $sub['payer_email'] ?? ($payment['payer_email'] ?? '');
          $doc   = $sub['payer_identification'] ?? ($payment['payer_identification'] ?? '');
          ?>
          <tr>
            <td><code><?php echo esc_html($sub['preapproval_id'] ?? '—'); ?></code></td>
            <td><code><?php echo esc_html($sub['plan_id'] ?? '—'); ?></code></td>
            <td><?php echo $name ? esc_html($name) : '—'; ?></td>
            <td><?php echo $email ? esc_html($email) : '—'; ?></td>
            <td><?php echo $doc ? esc_html($doc) : '—'; ?></td>
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

        foreach ($payments as $payment) {
          if (!empty($payment['payment_id']) && in_array($payment['payment_id'], $matched_payment_ids, true)) continue;
          $name = trim(($payment['payer_first_name'] ?? '').' '.($payment['payer_last_name'] ?? ''));
          ?>
          <tr>
            <td><code><?php echo esc_html($payment['preapproval_id'] ?? '—'); ?></code></td>
            <td><code><?php echo esc_html($payment['plan_id'] ?? '—'); ?></code></td>
            <td><?php echo $name ? esc_html($name) : '—'; ?></td>
            <td><?php echo !empty($payment['payer_email']) ? esc_html($payment['payer_email']) : '—'; ?></td>
            <td><?php echo !empty($payment['payer_identification']) ? esc_html($payment['payer_identification']) : '—'; ?></td>
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
