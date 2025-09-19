flowchart LR
  %% Nodos base
  subgraph WP["WordPress"]
    A1["Admin: Ajustes\nToken + Rol opcional"]
    A2["Admin: Sincronizar Planes"]
    A3["Admin: Generador de Botón\n(shortcode/bloque)"]
    A4["Admin: Suscriptores\n(lista + CSV + refresh)"]
    A5["Admin: Logs de Webhooks\n(últimos 50 + reprocesar)"]
    U1["Usuario logueado\nPágina con shortcode/bloque"]
    L1["Servidor WP: Genera checkout_url\n(plan_id normalizado)"]
    W1["REST: /wp-json/mp/v1/webhook"]
    M1["(user_meta)\n_suscripcion_activa\n_mp_preapproval_id\n_mp_plan_id"]
  end
  subgraph MP["Mercado Pago"]
    P1["(Planes\npreapproval_plan_id)"]
    C1{"Checkout\nPreapproval"}
    API["API MP"]
  end
  %% Flujos de administración
  A2 -->|"admin-post wpmps_sync_plans"| API
  API -->|"planes (search)"| A2
  A5 <-->|consulta evento / reprocesar| API
  %% Generación de botón / inserción
  A3 -->|elige plan/label| A3SC[Genera shortcode]
  A3SC -->|copiar/insertar| U1
  %% Flujo de usuario
  U1 -->|carga página| L1
  L1 -->|href checkout_url| U1
  U1 -->|click botón| C1
  C1 -->|autoriza| API
  C1 -->|cancela/falla| API
  %% Webhook y mapeo de acceso
  API -->|POST webhook\nid o data.id| W1
  W1 -->|"GET /preapproval/{id}\n(revalidar estado)"| API
  W1 -->|set yes/no + ids| M1
  M1 --> A4
  M1 --> URES["Back URL del sitio\nmuestra resultado"]
  %% Opcional rol
  M1 -->|"authorized → asigna rol\notro → quita rol"| R1["Rol suscriptor_premium"]
  %% Notas
  classDef note fill:#eef,stroke:#99c,color:#333;
  N1(["Seguridad: HTTPS, usuarios logueados,\nsanitización, sin PII en logs"]):::note
  N2(["Cache: planes via transient 20min"]):::note
  N3(["Logs: último 50 webhooks en opción"]):::note
  A1 --- N1
  A2 --- N2
  W1 --- N3
