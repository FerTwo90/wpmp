## Flujo de Suscripción y Webhook (WPMPS)

### Visión General
Este diagrama muestra cómo interactúan los componentes del plugin con WordPress y Mercado Pago durante el flujo de suscripción por preapproval, y dónde se registran los eventos (logger).

```mermaid
flowchart TD
  USER[Usuario en WP]
  PAGE[Página con shortcode]
  
  subgraph WP[WordPress Plugin]
    SC[Shortcode mp_subscribe]
    AUTH{Usuario logueado?}
    LOGIN[Redirect a login]
    RENDER[Generar botón]
    WH[REST Webhook]
    META[Actualizar user_meta]
    ROLE[Sincronizar rol]
    LOG[Sistema de Logs]
  end

  subgraph MP[Mercado Pago]
    CHECKOUT[Checkout Preapproval]
    API[API MP]
  end

  USER --> PAGE
  PAGE --> SC
  SC --> AUTH
  AUTH -->|No| LOGIN
  AUTH -->|Si| RENDER
  LOGIN -.-> AUTH
  RENDER --> CHECKOUT
  
  CHECKOUT --> API
  API --> WH
  WH --> API
  WH --> META
  META --> ROLE
  
  AUTH -.-> LOG
  RENDER -.-> LOG
  CHECKOUT -.-> LOG
  WH -.-> LOG
  META -.-> LOG
  
  classDef wp fill:#e1f5fe,stroke:#0277bd
  classDef mp fill:#fff3e0,stroke:#f57c00
  classDef log fill:#f3e5f5,stroke:#7b1fa2
  classDef user fill:#e8f5e8,stroke:#2e7d32
  
  class SC,AUTH,LOGIN,RENDER,WH,META,ROLE wp
  class CHECKOUT,API mp
  class LOG log
  class USER,PAGE user
```

### Datos que fluyen
- **payer_email**: del usuario WP logueado (para logging y webhook)
- **preapproval_plan_id**: aportado por el shortcode/bloque
- **checkout_url**: enlace generado a partir de dominio + preapproval_plan_id
- **preapproval_id**: ID devuelto por MP y usado en webhook
- **status**: authorized/paused/cancelled

### Canales de Log (Nuevo Sistema)
- **AUTH**: Eventos de autenticación (login requerido)
- **BUTTON**: Renderización de shortcodes y generación de links
- **CHECKOUT**: Interacciones con API de Mercado Pago
- **WEBHOOK**: Procesamiento de webhooks de MP
- **SUBSCRIPTION**: Cambios de estado y roles de suscripción
- **ADMIN**: Acciones administrativas
- **ERROR**: Errores críticos con códigos específicos

### Seguridad
- Requiere usuario logueado para mostrar el botón (evita accesos anónimos)
- HTTPS en sitio y Webhook
- Sanitización/escape en inputs/outputs y normalización de `plan_id`
- Logger filtra potenciales secretos y no guarda tokens
- Ring buffer de 500 eventos máximo para evitar crecimiento descontrolado
