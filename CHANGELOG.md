# Changelog

Todos los cambios notables de este proyecto serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto adhiere a [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Sistema de cron automático para sincronización MP ↔ WordPress cada 15 minutos
- Nueva pestaña "Cron" en el panel de administración con controles y estado
- Endpoint de prueba `/wp-json/mp/v1/webhook-test` para debugging
- Logging mejorado del webhook con información detallada del request
- Rate limiting en verificaciones de cron para evitar sobrecarga de la API de MP
- Sincronización inteligente que detecta desajustes entre estado MP y roles WP

### Changed
- Mejorada la captura de datos del webhook con múltiples métodos de extracción del preapproval_id
- El cron ahora sincroniza específicamente estado de suscripción MP con roles de usuario
- Lógica de sincronización más robusta que detecta y corrige desincronizaciones
- El sistema ahora es menos dependiente de webhooks de Mercado Pago

### Fixed
- Corregido error de sintaxis PHP en `src/admin/views/subscribers.php` - faltaba cerrar etiqueta PHP en línea 118 que causaba "unexpected end of file" en línea 342

## [Versiones anteriores]
<!-- Las versiones anteriores serán documentadas aquí -->