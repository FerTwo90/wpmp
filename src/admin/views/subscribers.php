<?php if (!defined('ABSPATH')) exit; ?>

<?php if (isset($_GET['cleaned'])): ?>
  <div class="notice notice-success is-dismissible">
    <p><?php printf(__('Se limpiaron %d usuarios con datos de tokens anteriores.', 'wp-mp-subscriptions'), intval($_GET['cleaned'])); ?></p>
  </div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
  <div class="notice notice-error is-dismissible">
    <p><?php echo esc_html(urldecode($_GET['error'])); ?></p>
  </div>
<?php endif; ?>

<?php
// Get filter parameters
$filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
$filter_sync = isset($_GET['filter_sync']) ? sanitize_text_field($_GET['filter_sync']) : '';
$filter_role = isset($_GET['filter_role']) ? sanitize_text_field($_GET['filter_role']) : '';
$filter_email = isset($_GET['filter_email']) ? sanitize_text_field($_GET['filter_email']) : '';

// Get filter parameters
$filter_priority = isset($_GET['filter_priority']) ? sanitize_text_field($_GET['filter_priority']) : '';

// Apply filters
$filters = array_filter([
  'status' => $filter_status,
  'sync_status' => $filter_sync,
  'priority' => $filter_priority,
  'email' => $filter_email
]);

// Debug info
$original_count = count($subs);
$debug_info = "Original: $original_count suscriptores";


if (!empty($subs)) {
  $debug_info .= " | Primer email: " . ($subs[0]['email'] ?? 'N/A');
}

if (!empty($filters)) {
  $debug_info .= " | Filtros activos: " . implode(', ', array_keys($filters));
  $subs = WPMPS_Subscribers::get_filtered_subscribers($filters);
  $filtered_count = count($subs);
  $debug_info .= " | Resultado: $filtered_count";
} else {
  // If no filters, use the data passed from render_subscribers
  // $subs is already defined from the controller
}
?>

<!-- Filtros -->
<form method="get" style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
  <input type="hidden" name="page" value="wpmps-subscribers" />
  
  <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
    <label>
      <?php _e('Estado MP:', 'wp-mp-subscriptions'); ?>
      <select name="filter_status">
        <option value=""><?php _e('Todos', 'wp-mp-subscriptions'); ?></option>
        <option value="authorized" <?php selected($filter_status, 'authorized'); ?>><?php _e('Autorizado', 'wp-mp-subscriptions'); ?></option>
        <option value="paused" <?php selected($filter_status, 'paused'); ?>><?php _e('Pausado', 'wp-mp-subscriptions'); ?></option>
        <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>><?php _e('Cancelado', 'wp-mp-subscriptions'); ?></option>
        <option value="pending" <?php selected($filter_status, 'pending'); ?>><?php _e('Pendiente', 'wp-mp-subscriptions'); ?></option>
      </select>
    </label>
    
    <label>
      <?php _e('Prioridad:', 'wp-mp-subscriptions'); ?>
      <select name="filter_priority">
        <option value=""><?php _e('Todos', 'wp-mp-subscriptions'); ?></option>
        <option value="actionable" <?php selected($_GET['filter_priority'] ?? '', 'actionable'); ?>><?php _e('Necesitan Acción', 'wp-mp-subscriptions'); ?></option>
        <option value="ok" <?php selected($_GET['filter_priority'] ?? '', 'ok'); ?>><?php _e('Todo OK', 'wp-mp-subscriptions'); ?></option>
        <option value="different_token" <?php selected($_GET['filter_priority'] ?? '', 'different_token'); ?>><?php _e('Token Anterior', 'wp-mp-subscriptions'); ?></option>
        <option value="irrelevant" <?php selected($_GET['filter_priority'] ?? '', 'irrelevant'); ?>><?php _e('Casos Archivados', 'wp-mp-subscriptions'); ?></option>
      </select>
    </label>
    
    <label>
      <?php _e('Email:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_email" value="<?php echo esc_attr($filter_email); ?>" placeholder="<?php _e('Buscar por email...', 'wp-mp-subscriptions'); ?>" />
    </label>
    
    <button type="submit" class="button"><?php _e('Filtrar', 'wp-mp-subscriptions'); ?></button>
    <a href="<?php echo esc_url(admin_url('admin.php?page=wpmps-subscribers')); ?>" class="button"><?php _e('Limpiar', 'wp-mp-subscriptions'); ?></a>
  </div>
</form>

<p>
  <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_export_csv'), 'wpmps_export_csv')); ?>" class="button">CSV</a>
  <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpmps_refresh_all'), 'wpmps_refresh_all')); ?>" class="button"><?php _e('Refrescar todos','wp-mp-subscriptions'); ?></a>
  <span style="margin-left: 20px; color: #666;">
    <?php printf(__('Mostrando %d suscriptores', 'wp-mp-subscriptions'), count($subs)); ?>
    <br><small style="color: #999;"><?php echo esc_html($debug_info); ?></small>
  </span>
</p>
  <table class="widefat fixed striped">
    <thead><tr>
      <th><?php _e('Email','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Usuario','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Rol','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Estado MP','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Plan','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Monto','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Preapproval ID','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Actualizado','wp-mp-subscriptions'); ?></th>
      <th><?php _e('Acciones','wp-mp-subscriptions'); ?></th>
    </tr></thead>
    <tbody>
      <?php if (empty($subs)): ?>
        <tr><td colspan="9" style="text-align: center; padding: 40px; color: #666;">
          <div>
            <strong><?php _e('No se encontraron suscriptores', 'wp-mp-subscriptions'); ?></strong><br>
            <small><?php _e('No hay usuarios con metadatos de suscripción o no pertenecen al token actual', 'wp-mp-subscriptions'); ?></small>
          </div>
        </td></tr>
      <?php else: 
        // Separate subscribers by priority and token status
        $actionable = [];
        $ok_cases = [];
        $irrelevant = [];
        $different_token = [];
        
        foreach ($subs as $s) {
          if ($s['sync_status'] === 'different_token') {
            $different_token[] = $s;
          } elseif ($s['sync_status'] === 'needs_role_change') {
            $actionable[] = $s;
          } elseif ($s['sync_status'] === 'ok') {
            $ok_cases[] = $s;
          } else {
            $irrelevant[] = $s;
          }
        }
        
        // Show info message if only different token cases exist
        if (empty($actionable) && empty($ok_cases) && empty($irrelevant) && !empty($different_token)): ?>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php foreach ($actionable as $s): ?>
        <tr style="background-color: #fff2cc;">
          <td><?php echo esc_html($s['email']); ?></td>
          <td><?php echo $s['user_id'] ? '<a href="'.esc_url(get_edit_user_link($s['user_id'])).'">'.intval($s['user_id']).'</a>' : '—'; ?></td>
          <td>
            <span style="color: #d68910; font-weight: bold;">
              <?php echo !empty($s['user_roles']) ? esc_html(implode(', ', $s['user_roles'])) : '—'; ?>
            </span>
          </td>
          <td>
            <span style="font-weight: bold; color: #c0392b;">
              <?php echo !empty($s['status']) ? esc_html($s['status']) : '—'; ?>
            </span>
          </td>
          <td><?php echo !empty($s['plan_name']) ? esc_html($s['plan_name']) : (!empty($s['plan_id']) ? esc_html($s['plan_id']) : '—'); ?></td>
          <td><?php echo (isset($s['amount']) && $s['amount']!=='') ? esc_html(number_format((float)$s['amount'], 2, ',', '.').' '.$s['currency']) : '—'; ?></td>
          <td><?php echo !empty($s['preapproval_id']) ? '<code>'.esc_html($s['preapproval_id']).'</code>' : '—'; ?></td>
          <td><?php echo !empty($s['updated_at']) ? esc_html($s['updated_at']) : '—'; ?></td>
          <td>
            <?php if (!empty($s['user_id'])): ?>
              <a class="button button-primary button-small" 
                 href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_change_to_pending&user_id='.$s['user_id']), 'wpmps_change_to_pending') ); ?>"
                 onclick="return confirm('<?php _e('¿Cambiar rol a pendiente?', 'wp-mp-subscriptions'); ?>')">
                <?php _e('Pasar a Pendiente', 'wp-mp-subscriptions'); ?>
              </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        
        <?php foreach ($ok_cases as $s): ?>
        <tr>
          <td><?php echo esc_html($s['email']); ?></td>
          <td><?php echo $s['user_id'] ? '<a href="'.esc_url(get_edit_user_link($s['user_id'])).'">'.intval($s['user_id']).'</a>' : '—'; ?></td>
          <td>
            <span style="color: #27ae60; font-weight: bold;">
              <?php echo !empty($s['user_roles']) ? esc_html(implode(', ', $s['user_roles'])) : '—'; ?>
            </span>
          </td>
          <td>
            <span style="font-weight: bold; color: #27ae60;">
              <?php echo !empty($s['status']) ? esc_html($s['status']) : '—'; ?>
            </span>
          </td>
          <td><?php echo !empty($s['plan_name']) ? esc_html($s['plan_name']) : (!empty($s['plan_id']) ? esc_html($s['plan_id']) : '—'); ?></td>
          <td><?php echo (isset($s['amount']) && $s['amount']!=='') ? esc_html(number_format((float)$s['amount'], 2, ',', '.').' '.$s['currency']) : '—'; ?></td>
          <td><?php echo !empty($s['preapproval_id']) ? '<code>'.esc_html($s['preapproval_id']).'</code>' : '—'; ?></td>
          <td><?php echo !empty($s['updated_at']) ? esc_html($s['updated_at']) : '—'; ?></td>
          <td>
            <span style="color: #27ae60; font-size: 18px;">—</span>
          </td>
        </tr>
        <?php endforeach; ?>
        
        <?php // Show different token cases first (more important than archived) - only if not empty
        if (!empty($different_token)): ?>
        <tr>
          <td colspan="9" style="background: #fff3cd; padding: 10px; border-top: 2px solid #ffc107; border-left: 4px solid #ffc107;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <details style="flex: 1;">
                <summary style="cursor: pointer; font-weight: bold; list-style: none; color: #856404;">
                  <span class="toggle-arrow">▶</span> <?php printf(__('⚠️ Ver %d suscripciones de token anterior/diferente', 'wp-mp-subscriptions'), count($different_token)); ?>
                </summary>
              </details>
              <div style="margin-left: 20px;">
                <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_cleanup_old_tokens'), 'wpmps_cleanup_old_tokens') ); ?>" 
                   class="button button-secondary" 
                   style="background: #dc3545; color: white; border-color: #dc3545;"
                   onclick="return confirm('⚠️ ADVERTENCIA: Esta acción eliminará PERMANENTEMENTE todos los metadatos de suscripciones de tokens anteriores.\n\nEsto incluye:\n- IDs de preapproval\n- IDs de planes\n- Estados de suscripción\n- Fechas de actualización\n\nEsta acción NO se puede deshacer.\n\n¿Estás seguro de que quieres continuar?')">
                  🗑️ <?php _e('Limpiar datos antiguos', 'wp-mp-subscriptions'); ?>
                </a>
              </div>
            </div>
          </td>
        </tr>
        <?php foreach ($different_token as $s): ?>
        <tr style="opacity: 0.8; background: #fff3cd;" class="different-token-row" style="display: none;">
          <td>
            <?php echo esc_html($s['email']); ?>
            <small style="color: #856404; display: block;">⚠️ Token diferente</small>
          </td>
          <td><?php echo $s['user_id'] ? '<a href="'.esc_url(get_edit_user_link($s['user_id'])).'">'.intval($s['user_id']).'</a>' : '—'; ?></td>
          <td><?php echo !empty($s['user_roles']) ? esc_html(implode(', ', $s['user_roles'])) : '—'; ?></td>
          <td>
            <span style="color: #856404;">
              <?php _e('No accesible', 'wp-mp-subscriptions'); ?>
            </span>
          </td>
          <td><?php echo !empty($s['plan_name']) ? esc_html($s['plan_name']) : (!empty($s['plan_id']) ? esc_html($s['plan_id']) : '—'); ?></td>
          <td>—</td>
          <td>
            <?php if (!empty($s['preapproval_id'])): ?>
              <code style="background: #fff3cd; color: #856404;"><?php echo esc_html($s['preapproval_id']); ?></code>
              <small style="display: block; color: #856404;">Token anterior</small>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?php echo !empty($s['updated_at']) ? esc_html($s['updated_at']) : '—'; ?></td>
          <td>
            <span style="color: #856404; font-size: 14px;" title="<?php _e('Suscripción de token anterior - no gestionable', 'wp-mp-subscriptions'); ?>">
              ⚠️
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
        
        <?php // Show archived cases under a collapsible section - only if not empty
        if (!empty($irrelevant)): ?>
        <tr>
          <td colspan="9" style="background: #f9f9f9; padding: 10px; border-top: 2px solid #ddd;">
            <details>
              <summary style="cursor: pointer; font-weight: bold; list-style: none;">
                <span class="toggle-arrow">▶</span> <?php printf(__('Ver %d casos archivados', 'wp-mp-subscriptions'), count($irrelevant)); ?>
              </summary>
            </details>
          </td>
        </tr>
        <?php foreach ($irrelevant as $s): ?>
        <tr style="opacity: 0.7; background: #f9f9f9;" class="archived-row" style="display: none;">
          <td><?php echo esc_html($s['email']); ?></td>
          <td><?php echo $s['user_id'] ? '<a href="'.esc_url(get_edit_user_link($s['user_id'])).'">'.intval($s['user_id']).'</a>' : '—'; ?></td>
          <td><?php echo !empty($s['user_roles']) ? esc_html(implode(', ', $s['user_roles'])) : '—'; ?></td>
          <td><?php echo !empty($s['status']) ? esc_html($s['status']) : '—'; ?></td>
          <td><?php echo !empty($s['plan_name']) ? esc_html($s['plan_name']) : (!empty($s['plan_id']) ? esc_html($s['plan_id']) : '—'); ?></td>
          <td><?php echo (isset($s['amount']) && $s['amount']!=='') ? esc_html(number_format((float)$s['amount'], 2, ',', '.').' '.$s['currency']) : '—'; ?></td>
          <td><?php echo !empty($s['preapproval_id']) ? '<code>'.esc_html($s['preapproval_id']).'</code>' : '—'; ?></td>
          <td><?php echo !empty($s['updated_at']) ? esc_html($s['updated_at']) : '—'; ?></td>
          <td><span style="color: #999; font-size: 18px;">—</span></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </tbody>
  </table>
<style>
details[open] summary .toggle-arrow {
  transform: rotate(90deg);
  transition: transform 0.2s ease;
}
details summary .toggle-arrow {
  display: inline-block;
  transition: transform 0.2s ease;
}
details summary {
  outline: none;
}
details summary::-webkit-details-marker {
  display: none;
}
.archived-row, .different-token-row {
  display: none;
}
.archived-row.show, .different-token-row.show {
  display: table-row;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Handle different token rows
  const differentTokenDetails = document.querySelector('details');
  const differentTokenRows = document.querySelectorAll('.different-token-row');
  
  if (differentTokenDetails && differentTokenRows.length > 0) {
    differentTokenDetails.addEventListener('toggle', function() {
      differentTokenRows.forEach(row => {
        if (differentTokenDetails.open) {
          row.classList.add('show');
        } else {
          row.classList.remove('show');
        }
      });
    });
  }
  
  // Handle archived rows
  const archivedDetails = document.querySelectorAll('details')[1]; // Second details element
  const archivedRows = document.querySelectorAll('.archived-row');
  
  if (archivedDetails && archivedRows.length > 0) {
    archivedDetails.addEventListener('toggle', function() {
      archivedRows.forEach(row => {
        if (archivedDetails.open) {
          row.classList.add('show');
        } else {
          row.classList.remove('show');
        }
      });
    });
  }
});
</script>