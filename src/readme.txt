WP MP Subscriptions (Preapproval)

Requisitos:
- Definir MP_ACCESS_TOKEN en wp-config.php
  define('MP_ACCESS_TOKEN','APP_USR-xxxxxxxx');

Instalación:
1) Comprimir la carpeta wp-mp-subscriptions en .zip
2) WP Admin > Plugins > Añadir nuevo > Subir plugin > Activar
3) En Mercado Pago, configurar webhook:
   https://TU-DOMINIO/wp-json/mp/v1/webhook
4) En una página WP, insertar:
   [mp_subscribe plan_id="PREAPPROVAL_PLAN_ID" label="Suscribirme" class="wp-mps-btn" back="/resultado-suscripcion"]
