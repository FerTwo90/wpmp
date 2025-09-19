## Flujo de Suscripción y Webhook (WPMPS)

### Visión General
Este diagrama muestra cómo interactúan los componentes del plugin con WordPress y Mercado Pago durante el flujo de suscripción por preapproval, y dónde se registran los eventos (logger).

```mermaid
flowchart LR
  %% Secciones
  subgraph WP[WordPress Plugin]
    SC["Shortcode/Bloque\n(mp_subscribe)"]
    RDR["Render y CTA\n(nocache_headers)"]
    LNK["Generar checkout_url\n(plan_id normalizado)"]
    LOG["[Logger\n(ring buffer + error_log)]"]
    WH[("REST Webhook\n/wp-json/mp/v1/webhook")]
    SUBS["Suscriptores\n(lista + refresh + CSV)"]
    CFG[Ajustes\n(Token/CTA login/Rol)]
    META[("user_meta:\n_suscripcion_activa\n_mp_preapproval_id\n_mp_plan_id\n_mp_updated_at")]
  end

  subgraph MP[Mercado Pago]
    API[(API MP)]
    CO{{Checkout\nPreapproval}}
  end

  %% Render y clic
  SC --> RDR
  RDR -->|usuario sin login| LOG
  RDR -->|link a login| RDR
  RDR -->|usuario logueado| LNK

  %% Link directo
  LNK -->|checkout_url| CO
  LNK -.log CREATE plan/checkout.-> LOG

  %% Webhook
  CO -->|autoriza/cancela| API
  API -->|POST webhook\n{id | data.id}| WH
  WH -->|GET /preapproval/{id}\nrevalidar estado| API
  WH --> META
  META --> SUBS
  WH -.log WEBHOOK id/estado/http.-> LOG

  %% Shortcode logs
  RDR -.log USER ctx=shortcode\n(is_user_logged_in, user_id/email,\nuri, referer, cache_hint).-> LOG

  %% Notas
  classDef note fill:#eef,stroke:#99c,color:#333;
  SEC(["Seguridad: HTTPS, usuarios logueados,\nsanitización/escape, sin tokens/PII en logs"]):::note
  CCH(["Planes cacheados con transient (20 min)"]):::note
  CFG --- SEC
  SUBS --- CCH
```

### Datos que fluyen
- payer_email: del usuario WP logueado (para logging y webhook).
- preapproval_plan_id: aportado por el shortcode/bloque.
- checkout_url: enlace generado a partir de dominio + preapproval_plan_id.
- preapproval_id: ID devuelto por MP y usado en webhook.
- status: authorized/paused/cancelled.

### Seguridad
- Requiere usuario logueado para mostrar el botón (evita accesos anónimos).
- HTTPS en sitio y Webhook.
- Sanitización/escape en inputs/outputs y normalización de `plan_id`.
- Logger filtra potenciales secretos y no guarda tokens.
