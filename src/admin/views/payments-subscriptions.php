<?php if (!defined('ABSPATH')) exit; ?>

<?php
$subscriptions_data    = isset($subscriptions_data) ? $subscriptions_data : ['success' => false, 'subscriptions' => []];
$subscriptions         = isset($subscriptions_data['subscriptions']) ? $subscriptions_data['subscriptions'] : [];
$subscriptions_total   = isset($subscriptions_data['total']) ? intval($subscriptions_data['total']) : count($subscriptions);
$seed_result           = isset($seed_result) ? $seed_result : ['seeded'=>false,'message'=>''];
$payments_seed_result  = isset($payments_seed_result) ? $payments_seed_result : ['seeded'=>false,'message'=>''];
$filters               = isset($filters) ? $filters : [];

// Variables no necesarias, las eliminamos
?>

<?php if (isset($_GET['table_reset'])): ?>
  <div class="notice notice-<?php echo $_GET['table_reset'] ? 'success' : 'error'; ?> is-dismissible" style="margin-top:15px;">
    <p><?php echo isset($_GET['reset_message']) ? esc_html(urldecode($_GET['reset_message'])) : 'Operación completada'; ?></p>
  </div>
<?php endif; ?>

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

<?php if (!$subscriptions_data['success']): ?>
  <div class="notice notice-warning" style="margin-top: 15px;">
    <p><?php _e('Aún no hay datos en la tabla de mapeo. Una vez que vuelques suscripciones desde Mercado Pago, aparecerán acá.', 'wp-mp-subscriptions'); ?></p>
  </div>
<?php endif; ?>

<div style="display:flex; flex-wrap:wrap; gap:20px; margin-top:20px;">
  <div style="flex:1; min-width:260px; background:#f9f9f9; border:1px solid #dcdcdc; padding:20px;">
    <h2 style="margin-top:0;"><?php _e('Resumen rápido', 'wp-mp-subscriptions'); ?></h2>
    <p><strong><?php _e('Suscripciones almacenadas:', 'wp-mp-subscriptions'); ?></strong> <?php echo esc_html(number_format_i18n($subscriptions_total)); ?></p>
    <p style="font-size:12px; color:#666;">
      <?php printf(esc_html__('Mostrando hasta %1$d filas (limit %2$d, offset %3$d)', 'wp-mp-subscriptions'),
        count($subscriptions),
        isset($subscriptions_data['limit']) ? intval($subscriptions_data['limit']) : 25,
        isset($subscriptions_data['offset']) ? intval($subscriptions_data['offset']) : 0
      ); ?>
    </p>
    <?php if (!empty($filters)): ?>
      <p style="font-size:12px; color:#666; margin-top:10px;"><?php _e('Filtros aplicados:', 'wp-mp-subscriptions'); ?> <code><?php echo esc_html(wp_json_encode($filters)); ?></code></p>
    <?php endif; ?>
    
    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
      <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_reset_payments_table'), 'wpmps_reset_payments_table')); ?>" 
         class="button button-secondary"
         onclick="return confirm('<?php _e('¿Está seguro? Esto borrará TODOS los datos de la tabla y se volverán a cargar desde Mercado Pago.', 'wp-mp-subscriptions'); ?>')">
        🗑️ <?php _e('Resetear Tabla', 'wp-mp-subscriptions'); ?>
      </a>
      <small style="color: #666; margin-left: 10px;">
        <?php _e('Borra todos los datos y vuelve a sincronizar desde cero', 'wp-mp-subscriptions'); ?>
      </small>
    </div>
  </div>
</div>

<!-- Filtros -->
<form method="get" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
  <input type="hidden" name="page" value="wpmps-payments-subscriptions" />
  
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
    <label>
      <?php _e('Estado:', 'wp-mp-subscriptions'); ?>
      <select name="filter_status" style="width: 100%;">
        <option value=""><?php _e('Todos', 'wp-mp-subscriptions'); ?></option>
        <option value="authorized" <?php selected($filters['status'] ?? '', 'authorized'); ?>><?php _e('Autorizado', 'wp-mp-subscriptions'); ?></option>
        <option value="paused" <?php selected($filters['status'] ?? '', 'paused'); ?>><?php _e('Pausado', 'wp-mp-subscriptions'); ?></option>
        <option value="cancelled" <?php selected($filters['status'] ?? '', 'cancelled'); ?>><?php _e('Cancelado', 'wp-mp-subscriptions'); ?></option>
        <option value="approved" <?php selected($filters['status'] ?? '', 'approved'); ?>><?php _e('Aprobado', 'wp-mp-subscriptions'); ?></option>
        <option value="pending" <?php selected($filters['status'] ?? '', 'pending'); ?>><?php _e('Pendiente', 'wp-mp-subscriptions'); ?></option>
      </select>
    </label>
    
    <label>
      <?php _e('Plan ID:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_plan_id" value="<?php echo esc_attr($filters['plan_id'] ?? ''); ?>" 
             placeholder="<?php _e('Buscar por plan...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Preapproval ID:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_preapproval_id" value="<?php echo esc_attr($filters['preapproval_id'] ?? ''); ?>" 
             placeholder="<?php _e('Buscar por preapproval...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Payment ID:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_payment_id" value="<?php echo esc_attr($filters['payment_id'] ?? ''); ?>" 
             placeholder="<?php _e('Buscar por payment...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Límite:', 'wp-mp-subscriptions'); ?>
      <select name="filter_limit" style="width: 100%;">
        <option value="25" <?php selected($filters['limit'] ?? 25, 25); ?>>25</option>
        <option value="50" <?php selected($filters['limit'] ?? 25, 50); ?>>50</option>
        <option value="100" <?php selected($filters['limit'] ?? 25, 100); ?>>100</option>
      </select>
    </label>
    
    <div>
      <button type="submit" class="button button-primary"><?php _e('Filtrar', 'wp-mp-subscriptions'); ?></button>
      <a href="<?php echo esc_url(admin_url('admin.php?page=wpmps-payments-subscriptions')); ?>" class="button"><?php _e('Limpiar', 'wp-mp-subscriptions'); ?></a>
    </div>
  </div>
</form>

<div style="margin-top:30px;">
  <h2><?php _e('Suscripciones y Pagos', 'wp-mp-subscriptions'); ?></h2>
  <table class="widefat fixed striped">
    <thead>
      <tr>
        <th><?php _e('Preapproval ID', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Plan Nombre', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Monto', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Nombre', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Email', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Documento', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Usuario WP', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Estado suscripción', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Fecha suscripción', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Payer ID', 'wp-mp-subscriptions'); ?></th>
        <th><?php _e('Payment ID', 'wp-mp-subscriptions'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php
      if (empty($subscriptions)) {
        echo '<tr><td colspan="11" style="text-align:center;">'.esc_html__('Aún no hay suscripciones en la tabla.', 'wp-mp-subscriptions').'</td></tr>';
      } else {
        foreach ($subscriptions as $sub) {
          $name = trim(($sub['payer_first_name'] ?? '').' '.($sub['payer_last_name'] ?? ''));
          $payment_ids = !empty($sub['payment_ids']) ? explode(',', $sub['payment_ids']) : [];
          ?>
          <tr>
            <td><code><?php echo esc_html($sub['preapproval_id'] ?? '—'); ?></code></td>
            <td><?php echo esc_html($sub['plan_name'] ?? $sub['plan_id'] ?? '—'); ?></td>
            <td><?php echo esc_html($sub['amount'] ? number_format($sub['amount'], 2) : '—'); ?></td>
            <td><?php echo $name ? esc_html($name) : '—'; ?></td>
            <td><?php echo esc_html($sub['payer_email'] ?? '—'); ?></td>
            <td><?php echo esc_html($sub['payer_identification'] ?? '—'); ?></td>
            <td><?php echo !empty($sub['user_id']) ? '<a href="'.esc_url(get_edit_user_link(intval($sub['user_id']))).'">'.intval($sub['user_id']).'</a>' : '—'; ?></td>
            <td>
              <?php 
              $status = $sub['status'] ?? '—';
              $status_color = '#666';
              switch(strtolower($status)) {
                case 'authorized':
                case 'active':
                  $status_color = '#00a32a';
                  break;
                case 'pending':
                  $status_color = '#dba617';
                  break;
                case 'cancelled':
                case 'paused':
                  $status_color = '#d63638';
                  break;
              }
              ?>
              <span style="padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; color: white; background: <?php echo $status_color; ?>;">
                <?php echo esc_html(strtoupper($status)); ?>
              </span>
            </td>
            <td><code><?php echo esc_html($sub['created_at'] ?? '—'); ?></code></td>
            <td><code><?php echo esc_html($sub['payer_id'] ?? '—'); ?></code></td>
            <td>
              <?php if (!empty($payment_ids)): ?>
                <?php foreach ($payment_ids as $payment_id): ?>
                  <code><?php echo esc_html(trim($payment_id)); ?></code><br>
                <?php endforeach; ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
          <?php
        }
      }
      ?>
    </tbody>
  </table>
</div>
