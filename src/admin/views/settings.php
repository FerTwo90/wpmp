<?php if (!defined('ABSPATH')) exit; ?>
<style>
.wpmps-card {background:#fff;border:1px solid #ccd0d4;padding:16px;margin:16px 0;border-radius:4px}
.wpmps-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;margin-left:8px}
.wpmps-badge.sandbox{background:#fff3cd;border:1px solid #ffe08a}
.wpmps-badge.prod{background:#d4edda;border:1px solid #a3d8a7}
.wpmps-token-wrap{display:flex;gap:8px;align-items:center;max-width:420px}
.wpmps-token-wrap .regular-text{flex:1}
.wpmps-token-toggle .dashicons{margin-top:4px}
</style>
<?php
  if (function_exists('wpmps_render_settings_inner')) {
    wpmps_render_settings_inner();
  } else {
    echo '<div class="wpmps-card">'.esc_html__('No se pudo cargar la vista de ajustes.', 'wp-mp-subscriptions').'</div>';
  }
?>
