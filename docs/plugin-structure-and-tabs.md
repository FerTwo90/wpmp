# WP MP Subscriptions — Guía de estructura y pestañas de administración

Este documento resume cada pieza del plugin dentro de `src/` y explica cómo seguir el patrón existente para añadir una nueva pestaña en el panel de administración.

---

## 1. Visión general del plugin

- **Entrada única:** `src/wp-mp-subscriptions.php` define constantes, carga helpers/clases, registra hooks globales (i18n, menús, cron) y declara los `register_activation/deactivation_hook`.
- **Backoffice:** `src/admin/` contiene la clase que arma el menú, los handlers `admin_post_*` y las vistas PHP que renderizan tabs como Ajustes, Planes o Suscriptores.
- **Servicios y dominio:** `src/includes/` concentra la lógica para Mercado Pago (cliente HTTP, sincronización de planes, cachés, cron, pagos, subscriptores, rutas REST, shortcodes, helpers comunes).
- **Recursos front:** `src/assets/` aporta CSS y JS para botones/modales y el listener de postMessage de Mercado Pago.
- **Editor:** `src/blocks/subscribe-button/` registra el bloque Gutenberg que encapsula el shortcode `[mp_subscribe]`.
- **i18n y docs:** `src/languages/` guarda `.pot/.mo` (placeholder con `.gitkeep`) y `src/plugin-estructura.md` mantiene un diagrama mermaid de alto nivel.

---

## 2. Archivos del nivel raíz (`src/`)

| Archivo | Función |
| --- | --- |
| `wp-mp-subscriptions.php` | Bootstrap del plugin: define constantes (`WPMPS_DIR`, `WPMPS_VER`), requiere helpers/clases, carga textos, arma cron para cache warming y limpia eventos al desactivar. |
| `plugin-estructura.md` | Documento mermaid para visualizar dependencias básicas. |
| `readme.txt` | Ficha compatible con el repositorio de WordPress (nombre, descripción, instrucciones). |

---

## 3. Administración (`src/admin/`)

### 3.1 Clases

| Archivo | Descripción |
| --- | --- |
| `class-wpmps-admin.php` | Núcleo del panel: agrega el menú `wpmps`, define cada `add_submenu_page`, pinta las tabs superiores (`tabs()`), renderiza cada sección (`render_settings`, `render_plans`, etc.) y expone todos los handlers `admin_post_*` (sincronizar planes, export CSV, refrescar caches, guardar mail, reprocesar webhooks, limpiar caché, etc.). |
| `class-wpmps-logs.php` | Encapsula las acciones `admin_post_wpmps_log_*` para limpiar o descargar el log en formato NDJSON usando `WPMPS_Logger`. |

### 3.2 Vistas (carpeta `admin/views/`)

Cada vista recibe datos desde los métodos `render_*` de `WPMPS_Admin` y se apoya en helpers o servicios.

| Vista | Propósito |
| --- | --- |
| `settings.php` | Incluye estilos mínimos y delega a `wpmps_render_settings_inner()` (defined en `includes/settings.php`) para mostrar token, webhook, rol automático y dominio de checkout. |
| `plans.php` | Lista planes traídos por `WPMPS_Sync`, permite refrescarlos vía `admin-post.php?action=wpmps_sync_plans` y genera botones para copiar shortcodes. |
| `subscribers.php` | Página más completa: filtros, alertas, acciones masivas (CSV, refrescos, limpieza de caché), y tabla con prioridades/estados provenientes de `WPMPS_Subscribers::get_subscribers()`. |
| `payments.php` | Dashboards y filtros para pagos/suscripciones. Consume `WPMPS_Payments::get_filtered_payments()` y `get_payments_stats()`, separa pagos mapeados/no mapeados y agrega acción para limpiar el caché de pagos. |
| `mail.php` | Formulario para habilitar/deshabilitar mails automáticos, definir remitente/formato, asunto y cuerpo (con vista previa). Guarda todo mediante `admin_post_wpmps_save_mail`. |
| `cron.php` | Muestra `WPMPS_Cron::get_status()`, botones para activar/desactivar cron, correrlo manualmente y ver últimos logs de canal `CRON`. |
| `logs.php` | Filtros por canal/email y tabla de eventos provenientes del ring buffer en `WPMPS_Logger`. Incluye botones para limpiar o descargar NDJSON. |
> Nota: existe un submenu "Pagos y Suscripciones" registrado en `WPMPS_Admin`, pero su render (ver sección 4) vive en `includes/class-wpmps-pagos-y-suscripciones.php`.

---

## 4. Lógica y servicios (`src/includes/`)

| Archivo | Resumen |
| --- | --- |
| `helpers.php` | Helpers generales: obtiene rutas/versiones del plugin, logging channelizado (`wpmps_log_*`), recolección de contexto (`wpmps_collect_context`), utilidades de tokens, helpers de checkout y URL actual, funciones de suscripción/rol, etc. |
| `class-wpmps-logger.php` | Implementa un ring buffer persistente en `wp_options` (`wpmps_log_ring`), sanitiza eventos y permite descargarlos/filtrarlos. |
| `class-mp-client.php` | Cliente HTTP para la API de Mercado Pago: `create_preapproval`, `get_preapproval`, búsquedas de planes/preapprovals y pagos, manejo de headers, logging y normalización de respuestas/errores. |
| `class-wpmps-sync.php` | Sincroniza planes (REST `/preapproval_plan/search`), cachea resultados (`transient` 20 minutos) y registra eventos en `wpmps_webhook_events`. |
| `class-wpmps-subscribers.php` | Obtiene suscriptores combinando usuarios WP + info de Mercado Pago (con límite de llamadas, caches persistentes/transients, metadata `_mp_*`). Expone métodos para refrescar usuarios, limpiar cachés, exportar CSV y mapear planes. |
| `class-wpmps-payments.php` | Recolecta preapprovals/pagos desde la API, hace paginación, convierte resultados a un formato uniforme, los mapea con usuarios locales (por email, plan, preapproval), calcula estadísticas y mantiene caché (`transient wpmps_payments_cache`). |
| `class-wpmps-pagos-y-suscripciones.php` | Clase simple con `render_pagos_y_suscripciones()` (vista placeholder) pensada para usarla desde `WPMPS_Admin`. |
| `class-wpmps-cron.php` | Gestiona el cron interno: registra `wpmps_check_subscriptions`, arma programación cada 15 min, sincroniza roles vs estado MP y expone `get_status()` para la vista. |
| `routes.php` | Registra endpoints REST `mp/v1/webhook` y `mp/v1/webhook-test`. `wpmps_handle_webhook()` valida firma mínima, obtiene `preapproval_id`, vuelve a consultar a MP, actualiza metas de usuario y logs. |
| `shortcodes.php` | Define el shortcode `[mp_subscribe]`, registra el bloque Gutenberg y genera enlaces de checkout (redirigiendo a Mercado Pago o login). |
| `settings.php` | Añade un `options_page` que redirige al slug interno del plugin y contiene `wpmps_render_settings_inner()` (formulario de token, dominio, rol, webhook). |

---

## 5. Recursos front (`src/assets/`)

- `css/mp-modal.css`: estilos detallados para el modal iFrame/SKD de Mercado Pago (overlay, botones, responsivo, estados de carga).
- `js/mp-integration.js`: integración avanzada con el SDK oficial (logs, apertura de modal, fallback, métricas).
- `js/mp-modal-simple.js`: versión reducida del script anterior (abre modal propio o SDK autoOpen).
- `js/mp-checkout-listener.js`: listener `postMessage` para recibir eventos desde el checkout, loguearlos vía AJAX y controlar el modal dinámico.

---

## 6. Bloques (`src/blocks/subscribe-button/`)

- `block.json`: metadatos del bloque (`wpmps/subscribe-button`, atributos `plan_id`, `label`, `class`).
- `index.js`: registra el bloque en Gutenberg, expone controles para plan/label/clase y muestra el shortcode resultante; el `save` es dinámico porque delega al shortcode en el servidor.

---

## 7. Idiomas y otros

- `src/languages/.gitkeep`: placeholder para permitir que Git rastree la carpeta (deberías reemplazarlo con `.po/.mo`).
- `src/plugin-estructura.md`: diagrama mermaid existente (puede coexistir con este documento).

---

## 8. Mini tutorial — Crear una nueva pestaña en el panel

Sigue estos pasos para añadir una pestaña coherente con el estilo actual:

1. **Define slug y permisos**  
   - El slug debe empezar con `wpmps-` (ej. `wpmps-reports`).  
   - Las pestañas existentes usan `manage_options`, por lo que debes mantener la misma capacidad salvo que necesites otra.

2. **Crea la vista** (`src/admin/views/reports.php`)  
   - Copia la estructura básica: `if (!defined('ABSPATH')) exit;` + HTML.  
   - Recibe las variables necesarias a través de `self::view('reports', ['key'=>$data])`.

3. **Registra el submenu** en `WPMPS_Admin::menu()` (`src/admin/class-wpmps-admin.php`)  
   ```php
   add_submenu_page(
     'wpmps',
     __('Reportes', 'wp-mp-subscriptions'),
     __('Reportes', 'wp-mp-subscriptions'),
     $cap,
     'wpmps-reports',
     [__CLASS__, 'render_reports']
   );
   ```

4. **Añade la etiqueta en la barra de tabs** dentro de `WPMPS_Admin::tabs()`  
   ```php
   'wpmps-reports' => __('Reportes', 'wp-mp-subscriptions'),
   ```

5. **Implementa el método render** en `WPMPS_Admin`  
   ```php
   public static function render_reports(){
     if (!current_user_can('manage_options')) return;
     $data = WPMPS_Payments::get_some_stats(); // Usa el servicio adecuado
     echo '<div class="wrap">';
     echo '<h1>'.esc_html__('Reportes', 'wp-mp-subscriptions').'</h1>';
     self::tabs('wpmps-reports');
     self::view('reports', ['data'=>$data]);
     echo '</div>';
   }
   ```

6. **Procesa formularios o acciones**  
   - Si la pestaña guarda datos, registra un handler `admin_post_wpmps_save_reports` en `init()` (igual que `handle_save_mail`).  
   - Usa `check_admin_referer`, sanitiza input y guarda mediante opciones, transients o tablas propias. Ubica la lógica de negocio en `src/includes/` (por ejemplo `class-wpmps-reports.php`) para mantener `WPMPS_Admin` libre de cálculos complejos.

7. **Reutiliza estilos/scripts existentes**  
   - Para botones/avisos, reutiliza las clases de WP (`button`, `notice`).  
   - Si necesitas JS/CSS específico, cola los assets desde `admin_enqueue_scripts` en `WPMPS_Admin::init()` o desde la vista usando `wp_enqueue_script`.

Con esta secuencia obtendrás un tab nuevo completamente integrado: aparece en el menú lateral, muestra su nav-tab activa, renderiza la vista dedicada y respeta el flujo de guardado/seguridad usado en el resto del plugin.

---

## 9. Próximos pasos sugeridos

- Completar documentación faltante en `src/plugin-estructura.md` (enlazar a este archivo).  
- Añadir pruebas manuales/diagnósticos bajo `test-payments-mapping.php` o CLI propios cuando se creen nuevas pestañas/servicios.  
- Mantener este documento actualizado al agregar archivos o servicios nuevos para conservar la trazabilidad del plugin.
