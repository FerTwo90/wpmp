# Sistema de Logs Mejorado

## Canales de Log

El sistema de logs ha sido reorganizado en canales específicos para facilitar el debugging y monitoreo:

### AUTH
**Cuándo se dispara:** Eventos relacionados con autenticación de usuarios
- `required`: Usuario no logueado intenta acceder a funcionalidad que requiere login
- `required_for_finalization`: Usuario no logueado intenta finalizar suscripción

**Información que provee:**
- `plan_id`: ID del plan que intentaba acceder
- `redirect_url`: URL a la que se redirigirá después del login
- `destination`: Página de destino

### BUTTON
**Cuándo se dispara:** Eventos relacionados con la renderización de botones de suscripción
- `render`: Se renderiza un shortcode/bloque de suscripción
- `link_generated`: Se genera exitosamente el link de checkout

**Información que provee:**
- `plan_id`: ID del plan (raw y normalizado)
- `user_logged`: Si el usuario está logueado
- `checkout_url`: URL generada para el checkout

### CHECKOUT
**Cuándo se dispara:** Eventos relacionados con la API de Mercado Pago para crear preapprovals
- `api_request`: Antes de hacer request a MP para crear preapproval
- `api_response`: Después de recibir respuesta de MP

**Información que provee:**
- `method`: Método HTTP (POST)
- `path`: Ruta de la API (/preapproval)
- `http_code`: Código de respuesta HTTP
- `has_auto_recurring`: Si tiene recurrencia automática

### WEBHOOK
**Cuándo se dispara:** Eventos relacionados con webhooks de Mercado Pago
- `received`: Se recibe un webhook de MP
- `validate_request`: Antes de validar preapproval con MP
- `validate_response`: Después de validar preapproval
- `processed`: Webhook procesado exitosamente

**Información que provee:**
- `preapproval_id`: ID del preapproval
- `status`: Estado de la suscripción
- `http_code`: Código de respuesta
- `headers`: Headers del webhook
- `raw_len`: Tamaño del payload

### SUBSCRIPTION
**Cuándo se dispara:** Eventos relacionados con cambios de estado de suscripciones
- `status_changed`: Cambio de estado de suscripción (solo si realmente cambió)
- `role_sync`: Sincronización de roles de usuario
- `finalize_page_accessed`: Usuario accede a página de finalización
- `synced_from_query`: Suscripción sincronizada desde parámetros URL
- `preapproval_detected`: Se detecta preapproval ID en finalización
- `fetch_attempt`: Intento de obtener datos de MP
- `fetch_success`: Datos obtenidos exitosamente de MP
- `finalize_complete`: Finalización completada con redirección

**Información que provee:**
- `user_id`: ID del usuario
- `user_email`: Email del usuario
- `preapproval_id`: ID del preapproval
- `old_status`/`new_status`: Estados anterior y nuevo
- `role_changed`: Si se cambió el rol
- `roles_before`/`roles_after`: Roles antes y después
- `status`: Estado actual

### ADMIN
**Cuándo se dispara:** Acciones administrativas
- `sync_plans`: Sincronización de planes desde MP
- `export_csv`: Exportación de suscriptores
- `refresh_subscriber`: Actualización de un suscriptor específico
- `refresh_all_subscribers`: Actualización de todos los suscriptores
- `save_mail_settings`: Guardado de configuración de emails
- `reprocess_webhook`: Reprocesamiento manual de webhook
- `token_from_constant`/`token_from_option`: Origen del token de acceso
- `mail_sent`: Envío de email
- `refresh_subscriber_start`/`refresh_subscriber_done`: Inicio/fin de actualización

**Información que provee:**
- `user_id`: ID del usuario afectado (cuando aplica)
- `preapproval_id`: ID del preapproval (cuando aplica)
- `token_hash`: Hash del token (para debugging sin exponer el token)
- `enabled`: Estado de configuración
- `email`: Email destinatario
- `sent`: Si el email se envió exitosamente

### ERROR
**Cuándo se dispara:** Errores críticos del sistema
- Errores de componentes específicos con códigos de error claros

**Información que provee:**
- `component`: Componente que generó el error
- `error_code`: Código específico del error
- `message`: Mensaje descriptivo del error
- Datos adicionales según el contexto

## Funciones Helper

Para facilitar el logging, se han creado funciones específicas:

```php
wpmps_log_auth($action, $extra = [])
wpmps_log_button($action, $extra = [])
wpmps_log_checkout($action, $extra = [])
wpmps_log_webhook($action, $extra = [])
wpmps_log_subscription($action, $extra = [])
wpmps_log_admin($action, $extra = [])
wpmps_log_error($component, $error_code, $message, $extra = [])
```

## Beneficios

1. **Logs más específicos**: Cada canal tiene un propósito claro
2. **Menos ruido**: Los logs están categorizados por funcionalidad
3. **Mejor debugging**: Es más fácil encontrar problemas específicos
4. **Información relevante**: Cada log contiene solo la información necesaria para su contexto
5. **Filtrado mejorado**: El admin puede filtrar por canal específico

## Migración

Los logs antiguos seguirán funcionando, pero gradualmente se han migrado al nuevo sistema. Los canales antiguos (`USER`, `CREATE`, `DEBUG`) han sido reemplazados por los nuevos canales más específicos.