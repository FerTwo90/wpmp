<?php if (!defined('ABSPATH')) exit; ?>

<?php
$payments_data      = isset($payments_data) ? $payments_data : ['success' => false, 'payments' => []];
$subscriptions_data = isset($subscriptions_data) ? $subscriptions_data : ['success' => false, 'subscriptions' => []];
$payments           = isset($payments_data['payments']) ? $payments_data['payments'] : [];
$subscriptions      = isset($subscriptions_data['subscriptions']) ? $subscriptions_data['subscriptions'] : [];
?>

<div class="notice notice-info is-dismissible" style="margin-top: 15px;">
  <p><?php _e('Esta vista es un punto de partida. Podés seguir desarrollando filtros, tablas y acciones sobre los datos de pagos y suscripciones aquí.', 'wp-mp-subscriptions'); ?></p>
</div>

<div style="display:flex; flex-wrap:wrap; gap:20px; margin-top:20px;">
  <div style="flex:1; min-width:260px; background:#f9f9f9; border:1px solid #dcdcdc; padding:20px;">
    <h2 style="margin-top:0;"><?php _e('Resumen rápido', 'wp-mp-subscriptions'); ?></h2>
    <p><strong><?php _e('Pagos recuperados:', 'wp-mp-subscriptions'); ?></strong> <?php echo esc_html(is_array($payments) ? count($payments) : 0); ?></p>
    <p><strong><?php _e('Suscripciones recuperadas:', 'wp-mp-subscriptions'); ?></strong> <?php echo esc_html(is_array($subscriptions) ? count($subscriptions) : 0); ?></p>
    <?php if (!empty($filters)): ?>
      <p style="font-size:12px; color:#666;"><?php _e('Filtros aplicados:', 'wp-mp-subscriptions'); ?> <?php echo esc_html(wp_json_encode($filters)); ?></p>
    <?php endif; ?>
  </div>
</div>

<div style="display:flex; flex-wrap:wrap; gap:30px; margin-top:30px;">
  <div style="flex:1; min-width:320px;">
    <h2><?php _e('Pagos (vista preliminar)', 'wp-mp-subscriptions'); ?></h2>
    <?php if (empty($payments)): ?>
      <p><?php _e('Aún no hay pagos para mostrar.', 'wp-mp-subscriptions'); ?></p>
    <?php else: ?>
      <table class="widefat fixed striped">
        <thead>
          <tr>
            <th><?php _e('ID', 'wp-mp-subscriptions'); ?></th>
            <th><?php _e('Estado', 'wp-mp-subscriptions'); ?></th>
            <th><?php _e('Email', 'wp-mp-subscriptions'); ?></th>
            <th><?php _e('Monto', 'wp-mp-subscriptions'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($payments, 0, 5) as $payment): ?>
            <tr>
              <td><code><?php echo esc_html($payment['id'] ?? '—'); ?></code></td>
              <td><?php echo esc_html($payment['status'] ?? '—'); ?></td>
              <td><?php echo esc_html($payment['payer']['email'] ?? ($payment['email'] ?? '—')); ?></td>
              <td><?php echo esc_html(isset($payment['transaction_amount']) ? $payment['transaction_amount'] : ($payment['amount'] ?? '—')); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="font-size:12px; color:#666;"><?php _e('Mostrando sólo los primeros 5 resultados para depurar. Extendé esta tabla según tus necesidades.', 'wp-mp-subscriptions'); ?></p>
    <?php endif; ?>
  </div>

  <div style="flex:1; min-width:320px;">
    <h2><?php _e('Suscripciones (vista preliminar)', 'wp-mp-subscriptions'); ?></h2>
    <?php if (empty($subscriptions)): ?>
      <p><?php _e('Aún no hay suscriptores para mostrar.', 'wp-mp-subscriptions'); ?></p>
    <?php else: ?>
      <table class="widefat fixed striped">
        <thead>
          <tr>
            <th><?php _e('Email', 'wp-mp-subscriptions'); ?></th>
            <th><?php _e('Estado MP', 'wp-mp-subscriptions'); ?></th>
            <th><?php _e('Plan', 'wp-mp-subscriptions'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($subscriptions, 0, 5) as $sub): ?>
            <tr>
              <td><?php echo esc_html($sub['email'] ?? '—'); ?></td>
              <td><?php echo esc_html($sub['status'] ?? '—'); ?></td>
              <td><?php echo esc_html($sub['plan_id'] ?? '—'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="font-size:12px; color:#666;"><?php _e('Mostrando sólo los primeros 5 registros. Podés reemplazar esta tabla con tus propios componentes.', 'wp-mp-subscriptions'); ?></p>
    <?php endif; ?>
  </div>
</div>
