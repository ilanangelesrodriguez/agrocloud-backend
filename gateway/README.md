# AgroCloud Gateway

El Gateway enruta las solicitudes a Identity, Agricultural, Notifications, Billing e IA. En Compose local usa el puerto 4000.

| Ruta | Contenido |
| --- | --- |
| `/` | Catálogo JSON con responsabilidades, prefijos, ejemplos de rutas y enlaces de salud |
| `/docs` | Swagger UI interactivo |
| `/openapi.json` | Contrato agregado con rutas del Gateway |
| `/health` | Estado del proceso |

Swagger está disponible en cualquier entorno y carga su interfaz desde jsDelivr. En **Authorize**, pega un token de Identity para ejecutar las operaciones protegidas. El contrato incluye las operaciones para clientes de Identity, Agricultural y Notifications, además de salud y disponibilidad de Billing e IA. Los flujos de compra y análisis para clientes se documentan en Identity y Agricultural.

El Gateway lee los contratos versionados de `packages/contracts/openapi`. La imagen Docker incluye ese directorio. Al cambiar código o contratos en Compose, reconstruye la imagen desde la raíz con `docker compose -f compose.yaml up -d --build --no-deps gateway`.
