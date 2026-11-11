# AgroCloud Backend

Monorepo con Gateway NestJS, Identity y Agricultural Laravel, Notifications y Billing NestJS, e IA FastAPI.

## Estado actual

Identity implementa autenticacion, sesiones, empresas, permisos, suscripciones, cupos y recepcion firmada de pagos. Agricultural implementa el dominio agricola, evidencias privadas, trabajos de IA y outbox. Notifications implementa bandeja, preferencias, deduplicacion y entregas. Billing conserva su integracion Culqi y outbox; IA utiliza Roboflow y Cloudinary.

El archivo `compose.yaml` es la entrada oficial del entorno local: incluye Dockerfiles especificos, configuracion interna, workers y almacenamiento persistente. Los tres Compose de servicio se conservan como overrides opcionales; no son necesarios para el arranque normal. Los comandos siguientes especifican `-f compose.yaml` para evitar una variable COMPOSE_FILE antigua.

## Preparacion local

Requisitos: Docker Compose v2. Para ejecucion manual: Node 22, PHP 8.4 con PDO PostgreSQL y Composer 2, Python 3.12.

1. Copiar `.env.example` a `.env` y completar claves y contrasenas independientes. IA y Notifications interpolan contrasenas en URLs: usar valores alfanumericos aleatorios o adaptar las URLs con escape correcto. Generar dos APP_KEY Laravel diferentes, con 32 bytes aleatorios en base64. Las claves internas y secretos de eventos requieren al menos 32 caracteres.
2. Revisar y respaldar las bases existentes antes de aplicar migraciones. Los comandos siguientes son instrucciones de aprovisionamiento, no se ejecutaron durante esta correccion.

```sh
docker compose -f compose.yaml up -d postgres mongo
docker compose -f compose.yaml build
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan identity:keys
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan migrate
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan db:seed
docker compose -f compose.yaml run --rm --no-deps ms-agricultural php artisan migrate
docker compose -f compose.yaml run --rm --no-deps ms-agricultural php artisan db:seed
docker compose -f compose.yaml run --rm --no-deps ms-notifications node scripts/migrate.mjs
docker compose -f compose.yaml up -d
```

El seed de Identity necesita IDENTITY_BOOTSTRAP_EMAIL e IDENTITY_BOOTSTRAP_PASSWORD para crear el administrador inicial; retirarlas despues. Agricultural carga catalogos. Notifications aplica su esquema explicitamente; su API no ejecuta DDL. IA ejecuta Alembic antes de Uvicorn. No se recrean bases ni se borran volumenes.

Los workers agricultural-worker y notifications-worker arrancan por defecto. Identity guarda el par RSA en identity-keys; API y worker agricola comparten agricultural-evidence. Las claves privadas y evidencias se excluyen del contexto de construccion.

Para correo de Identity configurar SMTP real y activar su worker:

```sh
docker compose -f compose.yaml --profile identity-mail up -d identity-mail-worker
```

Notifications mantiene NOTIFICATIONS_MAIL_ENABLED=false hasta configurar SMTP_URL y remitente; la bandeja sigue procesandose. Para Billing el modo predeterminado usa el temporizador del API. Si se prefiere un proceso independiente, cambiar BILLING_OUTBOX_TIMER_ENABLED=false en `.env` y entonces ejecutar:

```sh
docker compose -f compose.yaml --profile events up -d ms-billing billing-outbox-worker
```

PostgreSQL crea cuatro bases con usuarios separados al inicializar un volumen vacio. Cambiar `.env` no rota contrasenas en volumenes existentes: gestionar la rotacion por SQL. PostgreSQL y MongoDB no publican puertos al host. Los servidores PHP incluidos son para desarrollo.

| Servicio | Puerto local | Estado directo |
| --- | --- | --- |
| Gateway | 4000 | /health |
| Identity | 8001 | /up, /api/v1/health, /api/v1/ready |
| Agricultural | 8002 | /up, /api/v1/health, /api/v1/ready |
| IA | 8003 | /health, /ready |
| Billing | 4001 | /health, /ready |
| Notifications | 4002 | /health, /ready |

Las aplicaciones se publican en 127.0.0.1. Health indica vida del proceso; readiness no sustituye verificar el flujo completo. Inferencia requiere credenciales reales de Roboflow/Cloudinary y cobros requieren Culqi. IDENTITY_BILLING_OFFERS inicia vacio: configurar ofertas aprobadas antes de comprar.

## Gateway

El Gateway publica Swagger UI interactivo en `/docs` y el contrato agregado en `/openapi.json`, disponibles en cualquier entorno. La raíz enlaza ambos y presenta las rutas de los cinco servicios.

La raíz `GET /` devuelve un catálogo JSON de los cinco microservicios con su descripción, prefijo, rutas de ejemplo y enlaces de salud. Identity, Agricultural, Notifications, Billing e IA tienen su propio índice al acceder directamente a su puerto.

Identity y Agricultural sirven Swagger UI en `/docs` y el contrato en `/openapi.json` con `APP_ENV=local`. Notifications sirve las mismas rutas con `NODE_ENV=development`. Compose local configura esos entornos. Estas tres interfaces cargan Swagger UI desde jsDelivr y muestran las operaciones para clientes. Los contratos versionados conservan también las operaciones internas. Billing e IA mantienen su configuración de documentación y requieren sus claves de servicio en las rutas protegidas.

Para habilitar **Try it out** en Compose local, poner en `true` la opción del servicio en el `.env` raíz (`IDENTITY_SWAGGER_TRY_IT_OUT`, `AGRICULTURAL_SWAGGER_TRY_IT_OUT` o `NOTIFICATIONS_SWAGGER_TRY_IT_OUT`) y recrear el contenedor correspondiente. Para ejecución directa, usar `SWAGGER_TRY_IT_OUT=true` en el entorno del servicio. Usar el botón **Authorize** con un token de desarrollo para las rutas que requieren Bearer. Mantener privados los puertos reenviados y las claves de servicio fuera del frontend.

Compose lee el `.env` raíz y mapea sus variables a cada contenedor. Los `.env` de servicio corresponden a la ejecución independiente. Un cambio de entorno requiere recrear el contenedor afectado. Los cambios en los contratos, las plantillas de Swagger o el código requieren reconstruir su imagen. Las bases y sus migraciones son independientes de estos cambios.


`/api/v1/{identity|agricultural|billing|notifications|ai}/...` reenvia a `/api/v1/...`. Para GET/HEAD de health y ready utiliza el prefijo real de cada servicio: Laravel `/api/v1`, Node/IA sin prefijo.

La bandeja usa `/api/v1/notifications`, `/api/v1/notifications/unread-count`, `/api/v1/notifications/read-all` y `/api/v1/notifications/{id}/read`. Las preferencias usan `/api/v1/notifications/preferences`. Las rutas anteriores con doble notifications y notification-preferences siguen disponibles.

GATEWAY_CORS_ORIGINS acepta origenes HTTP(S) exactos separados por comas, sin rutas ni comodines. El valor local predeterminado es http://localhost:3000; la autenticacion usa Bearer, sin cookies CORS. Cada servicio valida credenciales, permisos y empresa. El Gateway conserva cuerpos y respuestas en streaming, aplica plazo de 120 segundos, bloquea rutas internal y elimina cabeceras de identidad y claves internas suministradas por clientes. No inyecta credenciales de servicio.

## Eventos y contratos

Billing entrega payment.succeeded al receptor firmado e idempotente de Identity mediante AGROCLOUD_EVENT_URL. Ambos comparten AGROCLOUD_EVENT_SECRET. Agricultural entrega su outbox a Notifications mediante NOTIFICATIONS_EVENT_URL y AGRICULTURAL_EVENT_SECRET. Las URLs predeterminadas apuntan a los receptores existentes dentro de Docker.

Los productores de eventos Identity/Billing hacia Notifications siguen pendientes; no se cambia el destino Billing-Identity para simular un fanout. En serverless Billing necesita un programador externo para su despacho interno cuando no existe un proceso persistente.

Los contratos completos de Identity, Agricultural y Notifications estan en `services/<servicio>/openapi.yaml`; Agricultural incluye `events.schema.json`. Los tres contratos se sincronizan con `packages/contracts/openapi`. Las convenciones para operaciones nuevas y la transición de v1 están en [Estándar API](docs/architecture/api-standard.md). Billing e IA exponen contratos generados en `/openapi.json`.

## Ejecucion manual y validacion

Node utiliza un solo lockfile: instalar desde la raiz con npm ci. Scripts: npm run dev:gateway, npm run dev:billing y npm run dev:notifications. Los `.env` locales de servicios usan direcciones del host; Compose utiliza el `.env` raiz y DNS de contenedores. Los Dockerfiles usan contexto raiz.

Los workflows realizan comprobaciones estaticas. En esta correccion no se ejecutaron tests, compilaciones, contenedores ni migraciones. El arranque integrado, SMTP, pagos e inferencias requieren validacion posterior con las credenciales configuradas.
