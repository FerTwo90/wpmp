<?php if (!defined('ABSPATH')) exit; ?>

<?php if (isset($_GET['payments_cache_cleared'])): ?>
  <div class="notice notice-success is-dismissible">
    <p><?php _e('Caché de pagos limpiado correctamente.', 'wp-mp-subscriptions'); ?></p>
  </div>
<?php endif; ?>

<?php
// Obtener parámetros de filtro
$filter_status = $filters['status'] ?? '';
$filter_match = $filters['match_type'] ?? '';
$filter_email = $filters['email'] ?? '';
$filter_amount_min = $filters['amount_min'] ?? '';
$filter_amount_max = $filters['amount_max'] ?? '';
$filter_plan_id = $filters['plan_id'] ?? '';
$filter_plan_name = $filters['plan_name'] ?? '';

// Obtener planes disponibles para el filtro
$available_plans = WPMPS_Payments::get_available_plans();
?>

<!-- Estadísticas -->
<?php if ($stats['success']): ?>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
  <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa;">
    <h4 style="margin: 0 0 5px 0;"><?php _e('Total Pagos', 'wp-mp-subscriptions'); ?></h4>
    <div style="font-size: 24px; font-weight: bold;"><?php echo number_format($stats['total_payments']); ?></div>
    <small><?php printf(__('$%s total', 'wp-mp-subscriptions'), number_format($stats['total_amount'], 2)); ?></small>
  </div>
  
  <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #00a32a;">
    <h4 style="margin: 0 0 5px 0;"><?php _e('Mapeados', 'wp-mp-subscriptions'); ?></h4>
    <div style="font-size: 24px; font-weight: bold; color: #00a32a;"><?php echo number_format($stats['matched_payments']); ?></div>
    <small><?php printf(__('$%s (%s%%)', 'wp-mp-subscriptions'), 
      number_format($stats['matched_amount'], 2),
      $stats['total_payments'] > 0 ? number_format(($stats['matched_payments'] / $stats['total_payments']) * 100, 1) : '0'
    ); ?></small>
  </div>
  
  <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #d63638;">
    <h4 style="margin: 0 0 5px 0;"><?php _e('Sin Mapear', 'wp-mp-subscriptions'); ?></h4>
    <div style="font-size: 24px; font-weight: bold; color: #d63638;"><?php echo number_format($stats['unmatched_payments']); ?></div>
    <small><?php printf(__('$%s (%s%%)', 'wp-mp-subscriptions'), 
      number_format($stats['unmatched_amount'], 2),
      $stats['total_payments'] > 0 ? number_format(($stats['unmatched_payments'] / $stats['total_payments']) * 100, 1) : '0'
    ); ?></small>
  </div>
  
  <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #dba617;">
    <h4 style="margin: 0 0 5px 0;"><?php _e('Estados', 'wp-mp-subscriptions'); ?></h4>
    <div style="font-size: 12px;">
      <?php foreach ($stats['by_status'] as $status => $data): ?>
        <div><strong><?php echo esc_html($status); ?>:</strong> <?php echo $data['count']; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Filtros -->
<form method="get" style="margin-bottom: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
  <input type="hidden" name="page" value="wpmps-payments" />
  
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
    <label>
      <?php _e('Estado del Pago:', 'wp-mp-subscriptions'); ?>
      <select name="filter_status" style="width: 100%;">
        <option value=""><?php _e('Todos', 'wp-mp-subscriptions'); ?></option>
        <option value="approved" <?php selected($filter_status, 'approved'); ?>><?php _e('Aprobado', 'wp-mp-subscriptions'); ?></option>
        <option value="pending" <?php selected($filter_status, 'pending'); ?>><?php _e('Pendiente', 'wp-mp-subscriptions'); ?></option>
        <option value="rejected" <?php selected($filter_status, 'rejected'); ?>><?php _e('Rechazado', 'wp-mp-subscriptions'); ?></option>
        <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>><?php _e('Cancelado', 'wp-mp-subscriptions'); ?></option>
        <option value="refunded" <?php selected($filter_status, 'refunded'); ?>><?php _e('Reembolsado', 'wp-mp-subscriptions'); ?></option>
      </select>
    </label>
    
    <label>
      <?php _e('Mapeo:', 'wp-mp-subscriptions'); ?>
      <select name="filter_match" style="width: 100%;">
        <option value=""><?php _e('Todos', 'wp-mp-subscriptions'); ?></option>
        <option value="preapproval_id" <?php selected($filter_match, 'preapproval_id'); ?>><?php _e('Por Preapproval ID', 'wp-mp-subscriptions'); ?></option>
        <option value="plan_id_email" <?php selected($filter_match, 'plan_id_email'); ?>><?php _e('Por Plan + Email', 'wp-mp-subscriptions'); ?></option>
        <option value="email" <?php selected($filter_match, 'email'); ?>><?php _e('Por Email', 'wp-mp-subscriptions'); ?></option>
        <option value="email_partial" <?php selected($filter_match, 'email_partial'); ?>><?php _e('Por Email Parcial', 'wp-mp-subscriptions'); ?></option>
        <option value="none" <?php selected($filter_match, 'none'); ?>><?php _e('Sin Mapear', 'wp-mp-subscriptions'); ?></option>
      </select>
    </label>
    
    <label>
      <?php _e('Email:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_email" value="<?php echo esc_attr($filter_email); ?>" placeholder="<?php _e('Buscar por email...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Monto Mín:', 'wp-mp-subscriptions'); ?>
      <input type="number" name="filter_amount_min" value="<?php echo esc_attr($filter_amount_min); ?>" step="0.01" min="0" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Monto Máx:', 'wp-mp-subscriptions'); ?>
      <input type="number" name="filter_amount_max" value="<?php echo esc_attr($filter_amount_max); ?>" step="0.01" min="0" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Plan de Suscripción:', 'wp-mp-subscriptions'); ?>
      <select name="filter_plan_id" style="width: 100%;">
        <option value=""><?php _e('Todos los planes', 'wp-mp-subscriptions'); ?></option>
        <?php foreach ($available_plans as $plan): ?>
          <option value="<?php echo esc_attr($plan['id']); ?>" <?php selected($filter_plan_id, $plan['id']); ?>>
            <?php echo esc_html($plan['name']); ?> (<?php echo $plan['count']; ?> suscriptores)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    
    <label>
      <?php _e('Nombre del Plan:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_plan_name" value="<?php echo esc_attr($filter_plan_name); ?>" placeholder="<?php _e('Buscar por nombre...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <div>
      <button type="submit" class="button button-primary"><?php _e('Filtrar', 'wp-mp-subscriptions'); ?></button>
      <a href="<?php echo esc_url(admin_url('admin.php?page=wpmps-payments')); ?>" class="button"><?php _e('Limpiar', 'wp-mp-subscriptions'); ?></a>
    </div>
  </div>
</form>

<!-- Acciones -->
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
  <div>
    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_clear_payments_cache'), 'wpmps_clear_payments_cache')); ?>" class="button button-secondary">🗑️ <?php _e('Limpiar Caché','wp-mp-subscriptions'); ?></a>
  </div>
  
  <div style="text-align: right; color: #666;">
    <?php if ($payments_data['success']): ?>
      <?php printf(__('Mostrando %d pagos', 'wp-mp-subscriptions'), count($payments_data['payments'])); ?>
      <?php if (isset($payments_data['total_found'])): ?>
        <?php printf(__(' de %d total', 'wp-mp-subscriptions'), $payments_data['total_found']); ?>
      <?php endif; ?>
      <?php if (isset($payments_data['processing_time'])): ?>
        <br><small style="color: #999;"><?php printf(__('Procesado en %ss', 'wp-mp-subscriptions'), $payments_data['processing_time']); ?></small>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$payments_data['success']): ?>
  <div class="notice notice-error">
    <p><strong><?php _e('Error:', 'wp-mp-subscriptions'); ?></strong> <?php echo esc_html($payments_data['message']); ?></p>
  </div>
<?php elseif (empty($payments_data['payments'])): ?>
  <div style="text-align: center; padding: 40px; background: #f9f9f9; border: 1px solid #ddd;">
    <h3><?php _e('No se encontraron pagos', 'wp-mp-subscriptions'); ?></h3>
    <p><?php _e('No hay pagos que coincidan con los filtros aplicados o no se pudieron obtener datos de MercadoPago.', 'wp-mp-subscriptions'); ?></p>
  </div>
<?php else: ?>

<!-- Tabla de Pagos -->
<table class="widefat fixed striped">
  <thead>
    <tr>
      <th style="width: 80px;"><?php _e('ID Pago','wp-mp-subscriptions'); ?></th>
      <th style="width: 100px;"><?php _e('Estado','wp-mp-subscriptions'); ?></th>
      <th style="width: 100px;"><?php _e('Monto','wp-mp-subscriptions'); ?></th>
      <th style="width: 200px;"><?php _e('Email Pagador','wp-mp-subscriptions'); ?></th>
      <th style="width: 120px;"><?php _e('Mapeo','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Suscriptor Mapeado','wp-mp-subscriptions'); ?></th>
      <th style="width: 150px;"><?php _e('Plan de Suscripción','wp-mp-subscriptions'); ?></th>
      <th style="width: 100px;"><?php _e('Método','wp-mp-subscriptions'); ?></th>
      <th style="width: 120px;"><?php _e('Fecha','wp-mp-subscriptions'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php 
    // Separar pagos por tipo de mapeo para mejor visualización
    $mapped_payments = [];
    $unmapped_payments = [];
    
    foreach ($payments_data['payments'] as $payment) {
      if ($payment['match_type'] !== 'none') {
        $mapped_payments[] = $payment;
      } else {
        $unmapped_payments[] = $payment;
      }
    }
    
    // Mostrar primero los mapeados
    foreach ($mapped_payments as $payment): 
      $match_confidence_color = [
        'high' => '#00a32a',
        'medium' => '#dba617', 
        'low' => '#d63638'
      ];
      $confidence_color = $match_confidence_color[$payment['match_confidence']] ?? '#666';
    ?>
    <tr style="background-color: <?php echo $payment['match_confidence'] === 'high' ? '#f0f8f0' : ($payment['match_confidence'] === 'medium' ? '#fff8e1' : '#ffeaea'); ?>">
      <td>
        <code><?php echo esc_html($payment['id']); ?></code>
      </td>
      <td>
        <span style="padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; color: white; background: <?php 
          echo $payment['status'] === 'approved' ? '#00a32a' : 
               ($payment['status'] === 'pending' ? '#dba617' : 
               ($payment['status'] === 'rejected' ? '#d63638' : '#666')); 
        ?>;">
          <?php echo esc_html(strtoupper($payment['status'])); ?>
        </span>
        <?php if (!empty($payment['status_detail'])): ?>
          <br><small style="color: #666;"><?php echo esc_html($payment['status_detail']); ?></small>
        <?php endif; ?>
      </td>
      <td>
        <strong><?php echo esc_html(number_format($payment['amount'], 2) . ' ' . $payment['currency']); ?></strong>
      </td>
      <td>
        <?php echo esc_html($payment['payer_email']); ?>
        <?php if (!empty($payment['payer_id'])): ?>
          <br><small style="color: #666;">ID: <?php echo esc_html($payment['payer_id']); ?></small>
        <?php endif; ?>
      </td>
      <td>
        <span style="color: <?php echo $confidence_color; ?>; font-weight: bold;">
          <?php 
          $match_labels = [
            'preapproval_id' => '✓ Preapproval',
            'plan_id_email' => '✓ Plan + Email',
            'email' => '✓ Email',
            'email_partial' => '~ Email Parcial'
          ];
          echo $match_labels[$payment['match_type']] ?? $payment['match_type'];
          ?>
        </span>
        <br><small style="color: <?php echo $confidence_color; ?>;">
          <?php echo ucfirst($payment['match_confidence']); ?>
        </small>
      </td>
      <td>
        <?php if ($payment['matched_subscriber']): ?>
          <strong><?php echo esc_html($payment['matched_subscriber']['email']); ?></strong>
          <?php if (!empty($payment['matched_subscriber']['user_id'])): ?>
            <br><a href="<?php echo esc_url(get_edit_user_link($payment['matched_subscriber']['user_id'])); ?>" target="_blank">
              <?php printf(__('Usuario #%d', 'wp-mp-subscriptions'), $payment['matched_subscriber']['user_id']); ?>
            </a>
          <?php endif; ?>
          <?php if (!empty($payment['matched_subscriber']['status'])): ?>
            <br><small style="color: #666;">Estado: <?php echo esc_html($payment['matched_subscriber']['status']); ?></small>
          <?php endif; ?>
        <?php else: ?>
          <span style="color: #999;">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($payment['matched_subscriber'] && !empty($payment['matched_subscriber']['plan_name'])): ?>
          <strong><?php echo esc_html($payment['matched_subscriber']['plan_name']); ?></strong>
          <?php if (!empty($payment['matched_subscriber']['plan_id'])): ?>
            <br><small style="color: #666;">ID: <?php echo esc_html($payment['matched_subscriber']['plan_id']); ?></small>
          <?php endif; ?>
        <?php else: ?>
          <span style="color: #999;">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php echo esc_html($payment['payment_method']); ?>
        <?php if (!empty($payment['payment_type'])): ?>
          <br><small style="color: #666;"><?php echo esc_html($payment['payment_type']); ?></small>
        <?php endif; ?>
      </td>
      <td>
        <?php echo esc_html(date('d/m/Y H:i', strtotime($payment['date_created']))); ?>
        <?php if (!empty($payment['date_approved']) && $payment['date_approved'] !== $payment['date_created']): ?>
          <br><small style="color: #666;">Aprob: <?php echo esc_html(date('d/m H:i', strtotime($payment['date_approved']))); ?></small>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    
    <?php if (!empty($unmapped_payments)): ?>
    <!-- Separador para pagos sin mapear -->
    <tr>
      <td colspan="9" style="background: #fff3cd; padding: 10px; border-top: 2px solid #ffc107; text-align: center;">
        <strong style="color: #856404;">⚠️ <?php printf(__('Pagos sin mapear (%d)', 'wp-mp-subscriptions'), count($unmapped_payments)); ?></strong>
      </td>
    </tr>
    
    <?php foreach ($unmapped_payments as $payment): ?>
    <tr style="background-color: #fff3cd; opacity: 0.8;">
      <td>
        <code><?php echo esc_html($payment['id']); ?></code>
      </td>
      <td>
        <span style="padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; color: white; background: <?php 
          echo $payment['status'] === 'approved' ? '#00a32a' : 
               ($payment['status'] === 'pending' ? '#dba617' : 
               ($payment['status'] === 'rejected' ? '#d63638' : '#666')); 
        ?>;">
          <?php echo esc_html(strtoupper($payment['status'])); ?>
        </span>
        <?php if (!empty($payment['status_detail'])): ?>
          <br><small style="color: #666;"><?php echo esc_html($payment['status_detail']); ?></small>
        <?php endif; ?>
      </td>
      <td>
        <strong><?php echo esc_html(number_format($payment['amount'], 2) . ' ' . $payment['currency']); ?></strong>
      </td>
      <td>
        <?php echo esc_html($payment['payer_email']); ?>
        <?php if (!empty($payment['payer_id'])): ?>
          <br><small style="color: #666;">ID: <?php echo esc_html($payment['payer_id']); ?></small>
        <?php endif; ?>
      </td>
      <td>
        <span style="color: #d63638; font-weight: bold;">✗ Sin mapear</span>
      </td>
      <td>
        <span style="color: #999;">—</span>
      </td>
      <td>
        <span style="color: #999;">—</span>
      </td>
      <td>
        <?php echo esc_html($payment['payment_method']); ?>
        <?php if (!empty($payment['payment_type'])): ?>
          <br><small style="color: #666;"><?php echo esc_html($payment['payment_type']); ?></small>
        <?php endif; ?>
      </td>
      <td>
        <?php echo esc_html(date('d/m/Y H:i', strtotime($payment['date_created']))); ?>
        <?php if (!empty($payment['date_approved']) && $payment['date_approved'] !== $payment['date_created']): ?>
          <br><small style="color: #666;">Aprob: <?php echo esc_html(date('d/m H:i', strtotime($payment['date_approved']))); ?></small>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

<?php endif; ?>

<style>
.widefat th, .widefat td {
  vertical-align: top;
  padding: 8px 10px;
}
.widefat code {
  background: #f1f1f1;
  padding: 2px 4px;
  border-radius: 3px;
  font-size: 11px;
}
</style>