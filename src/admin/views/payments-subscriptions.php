<?php if (!defined('ABSPATH')) exit; ?>

<?php
$payments_data      = isset($payments_data) ? $payments_data : ['success' => false, 'payments' => []];
$subscriptions_data = isset($subscriptions_data) ? $subscriptions_data : ['success' => false, 'subscriptions' => []];
$payments           = isset($payments_data['payments']) ? $payments_data['payments'] : [];
$subscriptions      = isset($subscriptions_data['subscriptions']) ? $subscriptions_data['subscriptions'] : [];
$payments_total     = isset($payments_data['total']) ? intval($payments_data['total']) : count($payments);
$subscriptions_total= isset($subscriptions_data['total']) ? intval($subscriptions_data['total']) : count($subscriptions);
?>

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
      <?php printf(esc_html__('Mostrando %1$d filas por tipo (limit %2$d, offset %3$d)', 'wp-mp-subscriptions'),
        count($payments),
        isset($payments_data['limit']) ? intval($payments_data['limit']) : 25,
        isset($payments_data['offset']) ? intval($payments_data['offset']) : 0
      ); ?>
    </p>
    <?php if (!empty($filters)): ?>
      <p style="font-size:12px; color:#666; margin-top:10px;"><?php _e('Filtros aplicados:', 'wp-mp-subscriptions'); ?> <code><?php echo esc_html(wp_json_encode($filters)); ?></code></p>
    <?php endif; ?>
  </div>
</div>

</div>
