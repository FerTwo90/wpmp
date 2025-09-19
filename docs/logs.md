## Guía de Logs (WPMPS)

### Dónde se almacenan
- Opción `wpmps_log_ring`: ring buffer con los últimos 500 eventos (FIFO).
- `wp-content/debug.log`: si `WP_DEBUG_LOG` está activo, se emiten también allí (formato línea JSON).

### Formato de las entradas
Cada evento es un JSON por línea. Campos típicos:

```json
{
  "ts": "2025-09-12T14:03:12Z",
  "channel": "USER|CREATE|WEBHOOK|ERROR",
  "ctx": "shortcode|action|webhook",
  "is_user_logged_in": true,
  "user_id": 123,
  "user_email": "mail@dominio.com",
  "uri": "/suscribirse",
  "referer": "https://...",
  "cache_hint": "HIT|MISS|...",
  "checkout_url": "https://www.mercadopago.com/...",
  "preapproval_id": "preapproval_123",
  "status": "authorized|paused|cancelled"
}
```

### Canales
- USER: render del shortcode/bloque. Incluye estado de login, user_id/email, uri/referer y `cache_hint` (CF-Cache-Status/X-Cache) si existe.
- CREATE: clic → generación de link. Incluye `plan_id` original/normalizado y el `checkout_url` armado para Mercado Pago.
- WEBHOOK: recepción del webhook y revalidación de estado (`GET /preapproval/{id}`). Incluye `preapproval_id`, `status` y código HTTP.
- ERROR: situaciones de seguridad o errores (nonce, permisos, token faltante, etc.).

### Ver y filtrar desde WP Admin
- Menú: MP Subscriptions → Logs.
- Tabla: fecha (ISO), canal, contexto, user_id, email y resumen.
- Filtros: por canal (USER/CREATE/WEBHOOK/ERROR) y búsqueda por email.
- Botones: “Descargar JSON” (NDJSON) y “Limpiar log” (borra el ring buffer).

### Filtrar por línea de comando (debug.log)
Si tu hosting permite `ssh`, podés usar `jq`/`rg`:

```bash
# Ver sólo eventos CREATE
rg "\[WPMPS\]" wp-content/debug.log | jq -c 'select(.channel=="CREATE")'

# Buscar por email
rg "\[WPMPS\]" wp-content/debug.log | jq -c 'select(.user_email|test("@tu-dominio\\.com$"))'

# Contar por canal
rg "\[WPMPS\]" wp-content/debug.log | jq -r '.channel' | sort | uniq -c
```

### Depuración de casos típicos
- No llega webhook: revisar eventos WEBHOOK; validar HTTP devuelto por WordPress y el `GET /preapproval/{id}`.
- Usuario “no logueado”: ver USER con `is_user_logged_in=false`, `uri`, `referer` y `cache_hint` (¿CDN cacheando?).
- Modal/link no abre: revisar evento CREATE y confirmar que `checkout_url` sea válido.
- Rol no asignado: chequear estado en WEBHOOK, opción de rol en Ajustes y metas del usuario (`_suscripcion_activa`).

### Privacidad y rotación
- No registrar PII sensible (no tarjetas). Se filtran tokens.
- Ring buffer FIFO (500). “Limpiar log” borra la opción `wpmps_log_ring`.
- `debug.log` queda bajo política del hosting.
