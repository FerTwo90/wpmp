<?php if (!defined('ABSPATH')) exit; ?>

<?php if (isset($_GET['updated'])): ?>
  <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Ajustes guardados.', 'wp-mp-subscriptions'); ?></p></div>
<?php endif; ?>

<p><?php echo esc_html__('Configurá el correo que vamos a usar en comunicaciones de suscripción. Podés usar variables.', 'wp-mp-subscriptions'); ?></p>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px;">
  <input type="hidden" name="action" value="wpmps_save_mail" />
  <?php wp_nonce_field('wpmps_mail_save'); ?>
  <table class="form-table" role="presentation">
    <tr>
      <th scope="row"><label for="wpmps_mail_enabled"><?php echo esc_html__('Habilitar correo de suscripción', 'wp-mp-subscriptions'); ?></label></th>
      <td>
        <label><input type="checkbox" id="wpmps_mail_enabled" name="wpmps_mail_enabled" value="1" <?php checked(!empty($mail_opts['enabled'])); ?> /> <?php echo esc_html__('Activar', 'wp-mp-subscriptions'); ?></label>
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="wpmps_mail_from_name"><?php echo esc_html__('Nombre del remitente', 'wp-mp-subscriptions'); ?></label></th>
      <td>
        <input type="text" class="regular-text" id="wpmps_mail_from_name" name="wpmps_mail_from_name" value="<?php echo esc_attr($mail_opts['from_name'] ?? 'Hoy Salgo'); ?>" />
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="wpmps_mail_from_email"><?php echo esc_html__('Email del remitente', 'wp-mp-subscriptions'); ?></label></th>
      <td>
        <input type="email" class="regular-text" id="wpmps_mail_from_email" name="wpmps_mail_from_email" value="<?php echo esc_attr($mail_opts['from_email'] ?? 'info@hoysalgo.com'); ?>" />
        <p class="description"><?php echo esc_html__('Email desde el cual se enviarán las notificaciones', 'wp-mp-subscriptions'); ?></p>
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="wpmps_mail_subject"><?php echo esc_html__('Asunto del correo', 'wp-mp-subscriptions'); ?></label></th>
      <td>
        <input type="text" class="regular-text" id="wpmps_mail_subject" name="wpmps_mail_subject" value="<?php echo esc_attr($mail_opts['subject'] ?? ''); ?>" />
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="wpmps_mail_format"><?php echo esc_html__('Formato del correo', 'wp-mp-subscriptions'); ?></label></th>
      <td>
        <label><input type="radio" name="wpmps_mail_format" value="text" <?php checked(($mail_opts['format'] ?? 'text'), 'text'); ?> /> <?php echo esc_html__('Texto plano', 'wp-mp-subscriptions'); ?></label><br>
        <label><input type="radio" name="wpmps_mail_format" value="html" <?php checked(($mail_opts['format'] ?? 'text'), 'html'); ?> /> <?php echo esc_html__('HTML', 'wp-mp-subscriptions'); ?></label>
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="wpmps_mail_body"><?php echo esc_html__('Mensaje', 'wp-mp-subscriptions'); ?></label></th>
      <td>
        <textarea class="large-text" rows="15" id="wpmps_mail_body" name="wpmps_mail_body"><?php echo esc_textarea($mail_opts['body'] ?? ''); ?></textarea>
        <p style="margin-top: 10px;">
          <button type="button" class="button" onclick="previewEmail()"><?php echo esc_html__('Vista previa', 'wp-mp-subscriptions'); ?></button>
        </p>
        <details style="margin-top: 10px;">
          <summary style="cursor: pointer; font-weight: bold; padding: 5px 0;"><?php echo esc_html__('Variables disponibles', 'wp-mp-subscriptions'); ?></summary>
          <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
            <p style="font-family: monospace; font-size: 12px; color: #666; margin: 0;">
              <code>{user_email}</code> - Email del usuario<br>
              <code>{user_display}</code> - Nombre para mostrar<br>
              <code>{preapproval_id}</code> - ID de la suscripción<br>
              <code>{plan_name}</code> - Nombre del plan<br>
              <code>{status}</code> - Estado de la suscripción<br>
              <code>{action_url}</code> - URL de acción
            </p>
          </div>
        </details>
      </td>
    </tr>
  </table>
  <p>
    <button type="submit" class="button button-primary"><?php echo esc_html__('Guardar cambios', 'wp-mp-subscriptions'); ?></button>
  </p>
</form>

<script>
function previewEmail() {
  const format = document.querySelector('input[name="wpmps_mail_format"]:checked').value;
  const subject = document.getElementById('wpmps_mail_subject').value;
  const body = document.getElementById('wpmps_mail_body').value;
  
  // Sample variables for preview
  const sampleVars = {
    user_email: 'usuario@ejemplo.com',
    user_login: 'usuario123',
    user_display: 'Juan Pérez',
    preapproval_id: 'ABC123456789',
    plan_name: 'Plan Premium',
    status: 'authorized',
    action_url: 'https://tudominio.com/mi-cuenta'
  };
  
  let previewBody = body;
  let previewSubject = subject;
  
  // Replace variables
  Object.keys(sampleVars).forEach(key => {
    const placeholder = '{' + key + '}';
    previewBody = previewBody.replace(new RegExp(placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), sampleVars[key]);
    previewSubject = previewSubject.replace(new RegExp(placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), sampleVars[key]);
  });
  
  // Open preview window
  const previewWindow = window.open('', 'emailPreview', 'width=600,height=700,scrollbars=yes');
  
  if (format === 'html') {
    previewWindow.document.write(`
      <html>
        <head>
          <title>Vista previa: ${previewSubject}</title>
          <style>body { margin: 0; padding: 20px; background: #f5f5f5; }</style>
        </head>
        <body>
          <h3 style="margin-bottom: 20px; color: #333;">Asunto: ${previewSubject}</h3>
          ${previewBody}
        </body>
      </html>
    `);
  } else {
    previewWindow.document.write(`
      <html>
        <head>
          <title>Vista previa: ${previewSubject}</title>
          <style>
            body { margin: 0; padding: 20px; background: #f5f5f5; font-family: monospace; }
            .email-content { background: white; padding: 20px; border: 1px solid #ddd; white-space: pre-wrap; }
          </style>
        </head>
        <body>
          <h3 style="margin-bottom: 20px; color: #333;">Asunto: ${previewSubject}</h3>
          <div class="email-content">${previewBody}</div>
        </body>
      </html>
    `);
  }
  
  previewWindow.document.close();
}
</script>
</form>
