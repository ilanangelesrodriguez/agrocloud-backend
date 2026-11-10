# AgroCloud Backend

Monorepo con Gateway NestJS, Identity y Agricultural Laravel, IA FastAPI, Billing y Notifications NestJS.

## Estado actual

Se preparó la infraestructura de desarrollo, la instalación Node centralizada y rutas de estado. Identity y Agricultural todavía no implementan autenticación ni entidades de negocio. Notifications tiene liveness, pero no bandeja ni persistencia. El Gateway reenvía solicitudes; la autenticación y autorización deben implementarse en cada servicio antes de exponer datos de negocio.

## Preparación local

Requisitos: Docker Compose v2; para ejecución manual, Node 22, PHP 8.4 con PDO PostgreSQL y Composer 2, Python 3.12.

1. Copiar `.env.example` a `.env` y completar contraseñas y claves. Usar contraseñas alfanuméricas para IA porque se interpolan en una URL. Generar claves Laravel distintas con `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`. Las claves internas de Billing, webhook y firma requieren al menos 32 caracteres. Culqi necesita claves de su entorno test.
2. Cuando se autorice construir y arrancar, usar `docker compose up --build -d` desde la raíz. Este comando no se ejecutó durante la corrección.
3. Las migraciones Laravel son explícitas: `docker compose exec ms-identity php artisan migrate` y `docker compose exec ms-agricultural php artisan migrate`. Revisar primero las migraciones iniciales: Agricultural aún conserva el esquema de usuarios del generador y no debe convertirse en una segunda autoridad de identidad. No se borraron datos ni migraciones.
4. IA ejecuta Alembic antes de Uvicorn; `AUTO_CREATE_TABLES=false`. Sin Roboflow y Cloudinary puede responder a liveness en desarrollo, pero readiness será 503 y la inferencia necesita esas credenciales.

PostgreSQL inicializa cuatro bases con usuarios distintos al crear un volumen vacío. Cambiar las contraseñas del `.env` después no cambia usuarios ya creados: gestionar la rotación por SQL. MongoDB y PostgreSQL no publican puertos al host. Las aplicaciones se publican solo en loopback. Compose usa una red propia del proyecto. Los servidores PHP incluidos son exclusivamente para desarrollo.

| Servicio | Puerto local | Estado |
| --- | --- | --- |
| Gateway | 4000 | `/health` |
| Identity | 8001 | `/up`, `/api/v1/health`, `/api/v1/ready` |
| Agricultural | 8002 | `/up`, `/api/v1/health`, `/api/v1/ready` |
| Billing | 4001 | `/health`, `/ready` |
| Notifications | 4002 | `/health` |
| IA | 8003 | `/health`, `/ready` |

Laravel readiness solo comprueba conexión, no migraciones ni funcionalidad de negocio. Notifications liveness no implica que exista persistencia.

## Gateway

`/api/v1/{identity|agricultural|billing|notifications|ai}/...` reenvía a `/api/v1/...` del servicio. Por ejemplo, `/api/v1/identity/health` llega a `/api/v1/health`. Propaga Authorization y X-API-Key del cliente, conserva cuerpos y respuestas en streaming, filtra cabeceras de salto e identidad no confiable y aplica un plazo de 120 segundos. No expone rutas `internal` por este proxy ni inyecta claves internas. No se ha implementado autenticación central, CORS para React, WebSocket, SSE ni controles de cuota.

## Workspaces y ejecución manual

`npm ci` se ejecuta solo en la raíz y utiliza un único lockfile. Scripts: `npm run dev:gateway`, `npm run dev:billing`, `npm run dev:notifications`. Cada script carga el `.env` opcional del servicio. Los ejemplos de esos servicios usan direcciones del host; Compose usa DNS de contenedores. Para ejecución manual se necesitan bases accesibles desde el host y configurar las credenciales y URLs respectivas; no usar direcciones Docker fuera de su red.

Los Dockerfiles Node reciben contexto raíz. El Dockerfile de Billing también requiere contexto raíz; no construirlo desde su carpeta como proyecto independiente.

## Eventos y contratos

Billing conserva `companyId` numérico. El schema de `payment.succeeded` refleja el payload actual. `AGROCLOUD_EVENT_URL` queda vacío hasta implementar un receptor idempotente en Identity que valide HMAC-SHA256 del cuerpo original (`X-AgroCloud-Signature`) y deduplique `X-Event-Id`. Mientras esté vacío, el outbox acumula pendientes sin enviarlos.

El proceso Billing usa su temporizador existente. En serverless (`VERCEL=1`) es obligatorio un programador externo que invoque `POST /api/v1/internal/outbox/dispatch` con X-API-Key. Un worker independiente todavía está pendiente. El Gateway bloquea esa ruta interna.

Los OpenAPI versionados cubren salud; Billing e IA mantienen sus contratos completos generados en `/openapi.json`. `notification.requested` es una propuesta, aún sin implementación. Ver `docs/architecture/overview.md` y `docs/database/data-ownership.md`.

## Validación

Los workflows raíz realizan comprobaciones estáticas de Node, PHP, Python y contratos, sin tests ni compilaciones. Durante esta corrección no se ejecutaron tests, builds, contenedores ni migraciones. El arranque integrado queda sin verificar hasta que se autorice ejecutarlo y se completen los secretos.
