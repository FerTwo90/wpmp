<?php if (!defined('ABSPATH')) exit; ?>

<div class="card" style="max-width: none;">
  <h2><?php _e('Estado del Cron', 'wp-mp-subscriptions'); ?></h2>
  
  <table class="form-table">
    <tr>
      <th scope="row"><?php _e('Estado', 'wp-mp-subscriptions'); ?></th>
      <td>
        <?php if ($status['enabled']): ?>
          <span style="color: #46b450; font-weight: bold;">✓ <?php _e('Habilitado', 'wp-mp-subscriptions'); ?></span>
        <?php else: ?>
          <span style="color: #dc3232; font-weight: bold;">✗ <?php _e('Deshabilitado', 'wp-mp-subscriptions'); ?></span>
        <?php endif; ?>
      </td>
    </tr>
    
    <tr>
      <th scope="row"><?php _e('Programado', 'wp-mp-subscriptions'); ?></th>
      <td>
        <?php if ($status['scheduled']): ?>
          <span style="color: #46b450;">✓ <?php _e('Sí', 'wp-mp-subscriptions'); ?></span>
        <?php else: ?>
          <span style="color: #dc3232;">✗ <?php _e('No', 'wp-mp-subscriptions'); ?></span>
        <?php endif; ?>
      </td>
    </tr>
    
    <?php if ($status['next_run']): ?>
    <tr>
      <th scope="row"><?php _e('Próxima ejecución', 'wp-mp-subscriptions'); ?></th>
      <td>
        <code><?php echo esc_html($status['next_run']); ?></code>
        <small style="color: #666;">
          (<?php echo esc_html(human_time_diff(strtotime($status['next_run']))); ?> <?php _e('desde ahora', 'wp-mp-subscriptions'); ?>)
        </small>
      </td>
    </tr>
    <?php endif; ?>
    
    <?php if ($status['last_run']): ?>
    <tr>
      <th scope="row"><?php _e('Última ejecución', 'wp-mp-subscriptions'); ?></th>
      <td>
        <code><?php echo esc_html($status['last_run']); ?></code>
        <small style="color: #666;">
          (<?php echo esc_html(human_time_diff(strtotime($status['last_run']))); ?> <?php _e('atrás', 'wp-mp-subscriptions'); ?>)
        </small>
      </td>
    </tr>
    <?php endif; ?>
    
    <tr>
      <th scope="row"><?php _e('Frecuencia', 'wp-mp-subscriptions'); ?></th>
      <td>
        <?php _e('Cada 15 minutos', 'wp-mp-subscriptions'); ?>
        <p class="description">
          <?php _e('El cron verifica automáticamente el estado de todas las suscripciones cada 15 minutos.', 'wp-mp-subscriptions'); ?>
        </p>
      </td>
    </tr>
  </table>
</div>

<div class="card" style="max-width: none; margin-top: 20px;">
  <h2><?php _e('Acciones', 'wp-mp-subscriptions'); ?></h2>
  
  <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
    <!-- Enable/Disable Cron -->
    <form method="post" style="display: inline;">
      <?php wp_nonce_field('wpmps_cron_settings'); ?>
      <?php if ($status['enabled']): ?>
        <input type="hidden" name="wpmps_cron_action" value="disable">
        <button type="submit" class="button button-secondary" 
                onclick="return confirm('<?php _e('¿Desactivar el cron automático?', 'wp-mp-subscriptions'); ?>')">
          <?php _e('Desactivar Cron', 'wp-mp-subscriptions'); ?>
        </button>
      <?php else: ?>
        <input type="hidden" name="wpmps_cron_action" value="enable">
        <button type="submit" class="button button-primary">
          <?php _e('Activar Cron', 'wp-mp-subscriptions'); ?>
        </button>
      <?php endif; ?>
    </form>
    
    <!-- Manual Run -->
    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'wpmps_run_cron'), 'wpmps_run_cron')); ?>" 
       class="button button-secondary"
       onclick="return confirm('<?php _e('¿Ejecutar verificación manual de suscripciones ahora?', 'wp-mp-subscriptions'); ?>')">
      <?php _e('Ejecutar Ahora', 'wp-mp-subscriptions'); ?>
    </a>
    
    <!-- View Logs -->
    <a href="<?php echo esc_url(admin_url('admin.php?page=wpmps-logs&channel=ADMIN')); ?>" 
       class="button button-secondary">
      <?php _e('Ver Logs del Cron', 'wp-mp-subscriptions'); ?>
    </a>
  </div>
</div>

<div class="card" style="max-width: none; margin-top: 20px;">
  <h2><?php _e('¿Cómo funciona?', 'wp-mp-subscriptions'); ?></h2>
  
  <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa;">
    <h4><?php _e('Verificación Automática de Suscripciones', 'wp-mp-subscriptions'); ?></h4>
    <p><?php _e('El sistema de cron reemplaza la dependencia de webhooks de Mercado Pago con verificaciones periódicas automáticas:', 'wp-mp-subscriptions'); ?></p>
    
    <ul style="margin-left: 20px;">
      <li><strong><?php _e('Cada 15 minutos:', 'wp-mp-subscriptions'); ?></strong> <?php _e('El cron se ejecuta automáticamente', 'wp-mp-subscriptions'); ?></li>
      <li><strong><?php _e('Busca usuarios:', 'wp-mp-subscriptions'); ?></strong> <?php _e('Con metadatos de suscripción (_mp_preapproval_id)', 'wp-mp-subscriptions'); ?></li>
      <li><strong><?php _e('Consulta MP:', 'wp-mp-subscriptions'); ?></strong> <?php _e('Verifica el estado actual de cada suscripción', 'wp-mp-subscriptions'); ?></li>
      <li><strong><?php _e('Actualiza datos:', 'wp-mp-subscriptions'); ?></strong> <?php _e('Si el estado cambió, actualiza user_meta y roles', 'wp-mp-subscriptions'); ?></li>
      <li><strong><?php _e('Rate limiting:', 'wp-mp-subscriptions'); ?></strong> <?php _e('Evita verificar el mismo usuario muy frecuentemente', 'wp-mp-subscriptions'); ?></li>
    </ul>
    
    <p><strong><?php _e('Ventajas sobre webhooks:', 'wp-mp-subscriptions'); ?></strong></p>
    <ul style="margin-left: 20px;">
      <li><?php _e('No depende de la configuración de webhooks en MP', 'wp-mp-subscriptions'); ?></li>
      <li><?php _e('Funciona aunque MP no envíe notificaciones', 'wp-mp-subscriptions'); ?></li>
      <li><?php _e('Detecta cambios de estado que los webhooks podrían perder', 'wp-mp-subscriptions'); ?></li>
      <li><?php _e('Más confiable para sitios con problemas de conectividad', 'wp-mp-subscriptions'); ?></li>
    </ul>
  </div>
</div>

<?php
// Show recent cron logs if available
$recent_logs = get_option('wpmps_log_ring', []);
if (!empty($recent_logs)) {
  $cron_logs = array_filter($recent_logs, function($log) {
    return isset($log['channel']) && $log['channel'] === 'ADMIN' && 
           isset($log['ctx']) && strpos($log['ctx'], 'cron_') === 0;
  });
  
  if (!empty($cron_logs)) {
    $cron_logs = array_slice(array_reverse($cron_logs), 0, 5); // Last 5 cron logs
    ?>
    <div class="card" style="max-width: none; margin-top: 20px;">
      <h2><?php _e('Últimas Ejecuciones del Cron', 'wp-mp-subscriptions'); ?></h2>
      
      <table class="widefat fixed striped">
        <thead>
          <tr>
            <th><?php _e('Fecha', 'wp-mp-subscriptions'); ?></th>
            <th><?php _e('Acción', 'wp-mp-subscriptions'); ?></th>
            <th><?php _e('Detalles', 'wp-mp-subscriptions'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cron_logs as $log): ?>
          <tr>
            <td>
              <?php if (isset($log['ts'])): ?>
                <code><?php echo esc_html(date('Y-m-d H:i:s', strtotime($log['ts']))); ?></code>
              <?php endif; ?>
            </td>
            <td>
              <?php 
              $action = str_replace('cron_', '', $log['ctx'] ?? '');
              echo '<code>' . esc_html($action) . '</code>';
              ?>
            </td>
            <td>
              <?php 
              $details = [];
              if (isset($log['users_processed'])) $details[] = $log['users_processed'] . ' procesados';
              if (isset($log['users_updated'])) $details[] = $log['users_updated'] . ' actualizados';
              if (isset($log['errors'])) $details[] = $log['errors'] . ' errores';
              if (isset($log['duration_seconds'])) $details[] = $log['duration_seconds'] . 's duración';
              echo esc_html(implode(' | ', $details));
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      
      <p style="margin-top: 10px;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=wpmps-logs&channel=ADMIN')); ?>" class="button button-small">
          <?php _e('Ver todos los logs del cron', 'wp-mp-subscriptions'); ?>
        </a>
      </p>
    </div>
    <?php
  }
}
?>