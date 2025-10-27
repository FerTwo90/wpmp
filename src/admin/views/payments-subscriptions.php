<?php if (!defined('ABSPATH')) exit; ?>

<?php
$subscriptions_data    = isset($subscriptions_data) ? $subscriptions_data : ['success' => false, 'subscriptions' => []];
$subscriptions         = isset($subscriptions_data['subscriptions']) ? $subscriptions_data['subscriptions'] : [];
$subscriptions_total   = isset($subscriptions_data['total']) ? intval($subscriptions_data['total']) : count($subscriptions);
$seed_result           = isset($seed_result) ? $seed_result : ['seeded'=>false,'message'=>''];
$smart_sync_result     = isset($smart_sync_result) ? $smart_sync_result : ['seeded'=>false,'message'=>''];
$user_mapping_result   = isset($user_mapping_result) ? $user_mapping_result : ['success'=>false,'message'=>''];
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

<?php if (!empty($smart_sync_result['message'])): ?>
  <div class="notice notice-<?php echo $smart_sync_result['seeded'] ? 'success' : 'info'; ?> is-dismissible" style="margin-top:15px;">
    <p><?php echo esc_html($smart_sync_result['message']); ?></p>
  </div>
<?php endif; ?>

<?php if (!empty($user_mapping_result['message'])): ?>
  <div class="notice notice-<?php echo ($user_mapping_result['success'] && $user_mapping_result['mapped'] > 0) ? 'success' : 'info'; ?> is-dismissible" style="margin-top:15px;">
    <p><?php echo esc_html($user_mapping_result['message']); ?></p>
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
      
      <button type="button" class="button button-secondary" onclick="debugPayer()">🔍 Debug Payer 39757231</button>
      
      <small style="color: #666; margin-left: 10px;">
        <?php _e('Borra todos los datos y vuelve a sincronizar desde cero', 'wp-mp-subscriptions'); ?>
      </small>
      
      <script>
      function debugPayer() {
        <?php 
        $debug_result = WPMPS_Payments_Subscriptions::debug_payer('39757231');
        ?>
        console.log('Debug Payer 39757231:', <?php echo wp_json_encode($debug_result); ?>);
        alert('Debug info enviado a la consola del navegador (F12)');
      }
      </script>
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
        <option value="authorized" <?php selected($filters['status'] ?? '', 'authorized'); ?>><?php _e('Autorizada', 'wp-mp-subscriptions'); ?></option>
        <option value="active" <?php selected($filters['status'] ?? '', 'active'); ?>><?php _e('Activa', 'wp-mp-subscriptions'); ?></option>
        <option value="pending" <?php selected($filters['status'] ?? '', 'pending'); ?>><?php _e('Pendiente', 'wp-mp-subscriptions'); ?></option>
        <option value="cancelled" <?php selected($filters['status'] ?? '', 'cancelled'); ?>><?php _e('Cancelada', 'wp-mp-subscriptions'); ?></option>
        <option value="paused" <?php selected($filters['status'] ?? '', 'paused'); ?>><?php _e('Pausada', 'wp-mp-subscriptions'); ?></option>
      </select>
    </label>
    
    <label>
      <?php _e('Plan:', 'wp-mp-subscriptions'); ?>
      <select name="filter_plan_id" style="width: 100%;">
        <option value=""><?php _e('Todos los planes', 'wp-mp-subscriptions'); ?></option>
        <?php 
        // Obtener planes únicos de las suscripciones
        global $wpdb;
        $table = WPMPS_Payments_Subscriptions::table_name();
        $plans = $wpdb->get_results("SELECT DISTINCT plan_id, plan_name FROM {$table} WHERE plan_id <> '' ORDER BY plan_name", ARRAY_A);
        foreach ($plans as $plan): ?>
          <option value="<?php echo esc_attr($plan['plan_id']); ?>" <?php selected($filters['plan_id'] ?? '', $plan['plan_id']); ?>>
            <?php echo esc_html($plan['plan_name'] ?: $plan['plan_id']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    
    <label>
      <?php _e('Preapproval ID:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_preapproval_id" value="<?php echo esc_attr($filters['preapproval_id'] ?? ''); ?>" placeholder="<?php _e('Buscar por ID...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Documento:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_document" value="<?php echo esc_attr($filters['payer_identification'] ?? ''); ?>" placeholder="<?php _e('Buscar por documento...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <label>
      <?php _e('Payment ID:', 'wp-mp-subscriptions'); ?>
      <input type="search" name="filter_payment_id" value="<?php echo esc_attr($filters['payment_id'] ?? ''); ?>" placeholder="<?php _e('Buscar por payment...', 'wp-mp-subscriptions'); ?>" style="width: 100%;" />
    </label>
    
    <div>
      <button type="submit" class="button button-primary"><?php _e('Filtrar', 'wp-mp-subscriptions'); ?></button>
      <a href="<?php echo esc_url(admin_url('admin.php?page=wpmps-payments-subscriptions')); ?>" class="button"><?php _e('Limpiar', 'wp-mp-subscriptions'); ?></a>
    </div>
  </div>
</form>

<div style="margin-top:30px;">
  <h2><?php _e('Suscripciones y Pagos', 'wp-mp-subscriptions'); ?></h2>
  
  <?php
  // Helper para generar enlaces de ordenamiento
  function sortable_link($column, $label, $filters) {
    $current_order = ($filters['orderby'] ?? '') === $column ? ($filters['order'] ?? 'DESC') : '';
    $new_order = ($current_order === 'ASC') ? 'DESC' : 'ASC';
    $arrow = $current_order === 'ASC' ? ' ↑' : ($current_order === 'DESC' ? ' ↓' : '');
    
    $args = array_merge($_GET, ['orderby' => $column, 'order' => $new_order]);
    $url = add_query_arg($args, admin_url('admin.php'));
    
    return '<a href="' . esc_url($url) . '">' . $label . $arrow . '</a>';
  }
  ?>
  
  <div style="overflow-x: auto;">
    <table class="widefat fixed striped" style="margin: 0 auto; table-layout: fixed; width: 100%;">
      <thead>
        <tr>
          <th style="width: 120px;"><?php echo sortable_link('preapproval_id', __('Preapproval ID', 'wp-mp-subscriptions'), $filters); ?></th>
          <th style="width: 150px;"><?php echo sortable_link('plan_name', __('Nombre del plan', 'wp-mp-subscriptions'), $filters); ?></th>
          <th style="width: 80px;"><?php echo sortable_link('amount', __('Monto', 'wp-mp-subscriptions'), $filters); ?></th>
          <th style="width: 120px;"><?php _e('Nombre', 'wp-mp-subscriptions'); ?></th>
          <th style="width: 150px;"><?php echo sortable_link('payer_email', __('Email MP', 'wp-mp-subscriptions'), $filters); ?></th>
          <th style="width: 100px;"><?php _e('Documento', 'wp-mp-subscriptions'); ?></th>
          <th style="width: 150px;"><?php _e('Usuario WP', 'wp-mp-subscriptions'); ?></th>
          <th style="width: 100px;"><?php echo sortable_link('status', __('Estado suscripción', 'wp-mp-subscriptions'), $filters); ?></th>
          <th style="width: 100px;"><?php echo sortable_link('created_at', __('Fecha suscripción', 'wp-mp-subscriptions'), $filters); ?></th>
          <th style="width: 80px;"><?php echo sortable_link('payer_id', __('Payer ID', 'wp-mp-subscriptions'), $filters); ?></th>
          <th style="width: 120px;"><?php _e('Órdenes posibles', 'wp-mp-subscriptions'); ?></th>
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
            <td>
              <?php if (!empty($sub['user_id'])): ?>
                <a href="<?php echo esc_url(get_edit_user_link(intval($sub['user_id']))); ?>" target="_blank">
                  <?php 
                  $wp_name = trim($sub['wp_full_name'] ?? '');
                  if (empty($wp_name)) {
                    $wp_name = $sub['wp_display_name'] ?? '';
                  }
                  if (empty($wp_name)) {
                    $wp_name = $sub['wp_user_email'] ?? '';
                  }
                  echo esc_html($wp_name ?: 'Usuario #' . $sub['user_id']);
                  ?>
                </a>
                <br><small style="color: #666;">
                  <?php 
                  if (!empty($sub['wp_user_email'])) {
                    echo esc_html($sub['wp_user_email']);
                  }
                  
                  // Mostrar rol del usuario
                  $user = get_user_by('id', intval($sub['user_id']));
                  if ($user && !empty($user->roles)) {
                    echo '<br>Rol: ' . esc_html(implode(', ', $user->roles));
                  }
                  ?>
                </small>
              <?php else: ?>
                <button type="button" class="button button-small" onclick="openUserModal(<?php echo $sub['id']; ?>, '<?php echo esc_js($sub['payer_email'] ?? ''); ?>')" title="<?php _e('Asociar usuario', 'wp-mp-subscriptions'); ?>">
                  🔍 <?php _e('Buscar', 'wp-mp-subscriptions'); ?>
                </button>
              <?php endif; ?>
            </td>
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

  <?php if ($subscriptions_total > 50): ?>
    <!-- Paginación -->
    <div style="margin: 20px 0; text-align: center;">
      <?php
      $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
      $total_pages = ceil($subscriptions_total / 50);
      $base_url = admin_url('admin.php?page=wpmps-payments-subscriptions');
      
      // Mantener filtros en la paginación
      $query_args = [];
      foreach (['filter_status', 'filter_plan_id', 'filter_preapproval_id', 'filter_document', 'filter_payment_id'] as $filter) {
        if (!empty($_GET[$filter])) {
          $query_args[$filter] = $_GET[$filter];
        }
      }
      
      if ($current_page > 1): ?>
        <a href="<?php echo esc_url(add_query_arg(array_merge($query_args, ['paged' => $current_page - 1]), $base_url)); ?>" class="button">« <?php _e('Anterior', 'wp-mp-subscriptions'); ?></a>
      <?php endif; ?>
      
      <span style="margin: 0 15px;">
        <?php printf(__('Página %d de %d', 'wp-mp-subscriptions'), $current_page, $total_pages); ?>
        (<?php printf(__('%d suscripciones total', 'wp-mp-subscriptions'), $subscriptions_total); ?>)
      </span>
      
      <?php if ($current_page < $total_pages): ?>
        <a href="<?php echo esc_url(add_query_arg(array_merge($query_args, ['paged' => $current_page + 1]), $base_url)); ?>" class="button"><?php _e('Siguiente', 'wp-mp-subscriptions'); ?> »</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Modal para asociar usuario -->
<div id="user-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
  <div style="background-color: #fff; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 5px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <h3><?php _e('Asociar Usuario de WordPress', 'wp-mp-subscriptions'); ?></h3>
      <button type="button" onclick="closeUserModal()" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
    </div>
    
    <div style="margin-bottom: 15px;">
      <label><?php _e('Buscar usuario:', 'wp-mp-subscriptions'); ?></label>
      <input type="text" id="user-search" placeholder="<?php _e('Escriba nombre, email o ID...', 'wp-mp-subscriptions'); ?>" style="width: 100%; padding: 8px; margin-top: 5px;" />
    </div>
    
    <div id="user-results" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
      <p><?php _e('Escriba para buscar usuarios...', 'wp-mp-subscriptions'); ?></p>
    </div>
    
    <div style="margin-top: 20px; text-align: right;">
      <button type="button" class="button" onclick="closeUserModal()"><?php _e('Cancelar', 'wp-mp-subscriptions'); ?></button>
    </div>
  </div>
</div>

<script>
let currentSubId = null;

function openUserModal(subId, email) {
  currentSubId = subId;
  document.getElementById('user-modal').style.display = 'block';
  document.getElementById('user-search').value = email;
  if (email) {
    searchUsers(email);
  }
}

function closeUserModal() {
  document.getElementById('user-modal').style.display = 'none';
  currentSubId = null;
  document.getElementById('user-search').value = '';
  document.getElementById('user-results').innerHTML = '<p><?php _e('Escriba para buscar usuarios...', 'wp-mp-subscriptions'); ?></p>';
}

function searchUsers(query) {
  if (query.length < 2) {
    document.getElementById('user-results').innerHTML = '<p><?php _e('Escriba al menos 2 caracteres...', 'wp-mp-subscriptions'); ?></p>';
    return;
  }
  
  // Obtener usuarios asociados para excluirlos
  <?php
  global $wpdb;
  $table = WPMPS_Payments_Subscriptions::table_name();
  $associated_users = $wpdb->get_col("SELECT DISTINCT user_id FROM {$table} WHERE user_id IS NOT NULL AND user_id > 0");
  ?>
  const excludeUsers = <?php echo wp_json_encode($associated_users); ?>;
  
  const data = new FormData();
  data.append('action', 'wpmps_search_users');
  data.append('query', query);
  data.append('exclude', excludeUsers.join(','));
  data.append('nonce', '<?php echo wp_create_nonce('wpmps_search_users'); ?>');
  
  fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
    method: 'POST',
    body: data
  })
  .then(response => response.json())
  .then(users => {
    let html = '';
    if (users.length === 0) {
      html = '<p><?php _e('No se encontraron usuarios disponibles.', 'wp-mp-subscriptions'); ?></p>';
    } else {
      users.forEach(user => {
        html += `<div style="padding: 10px; border-bottom: 1px solid #eee; cursor: pointer;" onclick="associateUser(${user.ID})">
          <strong>${user.display_name}</strong> (${user.user_email})
          <br><small>ID: ${user.ID} | Roles: ${user.roles.join(', ')}</small>
        </div>`;
      });
    }
    document.getElementById('user-results').innerHTML = html;
  })
  .catch(error => {
    document.getElementById('user-results').innerHTML = '<p style="color: red;"><?php _e('Error al buscar usuarios.', 'wp-mp-subscriptions'); ?></p>';
  });
}

function associateUser(userId) {
  if (!currentSubId) return;
  
  const data = new FormData();
  data.append('action', 'wpmps_associate_user');
  data.append('sub_id', currentSubId);
  data.append('user_id', userId);
  data.append('nonce', '<?php echo wp_create_nonce('wpmps_associate_user'); ?>');
  
  fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
    method: 'POST',
    body: data
  })
  .then(response => response.json())
  .then(result => {
    if (result.success) {
      closeUserModal();
      location.reload(); // Recargar para mostrar el usuario asociado
    } else {
      alert('Error: ' + result.message);
    }
  })
  .catch(error => {
    alert('<?php _e('Error al asociar usuario.', 'wp-mp-subscriptions'); ?>');
  });
}

// Buscar mientras escribe
document.addEventListener('DOMContentLoaded', function() {
  const searchInput = document.getElementById('user-search');
  if (searchInput) {
    let timeout;
    searchInput.addEventListener('input', function() {
      clearTimeout(timeout);
      timeout = setTimeout(() => searchUsers(this.value), 300);
    });
  }
});

// Cerrar modal al hacer clic fuera
document.getElementById('user-modal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeUserModal();
  }
});
</script>

<style>
.widefat th, .widefat td {
  text-align: center;
  vertical-align: middle;
  word-wrap: break-word;
  overflow-wrap: break-word;
}
.widefat th a {
  color: inherit;
  text-decoration: none;
}
.widefat th a:hover {
  color: #0073aa;
}
</style>