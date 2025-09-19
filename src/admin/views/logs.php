<?php if (!defined('ABSPATH')) exit; ?>
<?php
$channel = isset($_GET['channel']) ? sanitize_text_field(wp_unslash($_GET['channel'])) : '';
$email_q = isset($_GET['email']) ? sanitize_text_field(wp_unslash($_GET['email'])) : '';
$items = class_exists('WPMPS_Logger') ? WPMPS_Logger::filtered(['channel'=>$channel,'email'=>$email_q]) : [];
?>

<form method="get" style="margin-top:10px;">
  <input type="hidden" name="page" value="wpmps-logs" />
  <label><?php _e('Canal','wp-mp-subscriptions'); ?>
    <select name="channel">
      <option value="">— <?php _e('Todos','wp-mp-subscriptions'); ?> —</option>
      <?php foreach (['USER','CREATE','WEBHOOK','ERROR','DEBUG'] as $ch): ?>
        <option value="<?php echo esc_attr($ch); ?>" <?php selected(strtoupper($channel), $ch); ?>><?php echo esc_html($ch); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label style="margin-left:10px;">
    <?php _e('Email','wp-mp-subscriptions'); ?>
    <input type="search" name="email" value="<?php echo esc_attr($email_q); ?>" />
  </label>
  <button class="button"><?php _e('Filtrar','wp-mp-subscriptions'); ?></button>
  <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_log_clear'), 'wpmps_log_clear') ); ?>" onclick="return confirm('¿Limpiar todos los eventos?');"><?php _e('Limpiar log','wp-mp-subscriptions'); ?></a>
  <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_log_download'), 'wpmps_log_download') ); ?>"><?php _e('Descargar JSON','wp-mp-subscriptions'); ?></a>
</form>

<table class="widefat fixed striped" style="margin-top:10px;">
  <thead><tr>
    <th><?php _e('Fecha','wp-mp-subscriptions'); ?></th>
    <th><?php _e('Canal','wp-mp-subscriptions'); ?></th>
    <th><?php _e('Ctx','wp-mp-subscriptions'); ?></th>
    <th><?php _e('User ID','wp-mp-subscriptions'); ?></th>
    <th><?php _e('Email','wp-mp-subscriptions'); ?></th>
    <th><?php _e('Resumen','wp-mp-subscriptions'); ?></th>
    <th><?php _e('Acciones','wp-mp-subscriptions'); ?></th>
  </tr></thead>
  <tbody>
    <?php if (empty($items)): ?>
      <tr><td colspan="6"><?php _e('Sin eventos aún.', 'wp-mp-subscriptions'); ?></td></tr>
    <?php else: foreach ($items as $e): ?>
      <tr>
        <td><code><?php echo esc_html($e['ts'] ?? ''); ?></code></td>
        <td><?php echo esc_html($e['channel'] ?? ''); ?></td>
        <td><?php echo esc_html($e['ctx'] ?? ''); ?></td>
        <td><?php echo isset($e['user_id']) ? intval($e['user_id']) : 0; ?></td>
        <td><?php echo esc_html($e['user_email'] ?? ''); ?></td>
        <td>
          <?php
            // Build a concise summary prioritizing HTTP + MP details, then context
            $keys = [
              'method','path','http_code','ok','mp_error','mp_message','mp_cause_code','mp_cause_desc',
              'role_applied','roles_before','roles_after',
              'init_point','preapproval_id','status','plan_id','amount','currency','back','back_url',
              'state','reason','uri','cache_hint','body_raw_preview','request_id'
            ];
            $parts = [];
            foreach ($keys as $k){
              if (!isset($e[$k]) || $e[$k] === '' || $e[$k] === null) continue;
              $val = $e[$k];
              if (is_array($val)){
                // Render simple arrays (like roles) as comma-separated
                $val = implode(',', array_map('strval', $val));
              } elseif (!is_scalar($val)) {
                $val = '';
              }
              if ($val !== '') $parts[] = $k.': '.$val;
            }
            echo esc_html(implode(' | ', $parts));
          ?>
        </td>
        <td>
          <?php if (!empty($e['preapproval_id'])): ?>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=wpmps_reprocess&preapproval_id='.rawurlencode($e['preapproval_id'])), 'wpmps_reprocess') ); ?>"><?php _e('Reprocesar','wp-mp-subscriptions'); ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>
