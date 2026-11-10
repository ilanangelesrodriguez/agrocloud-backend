# Estándar API de AgroCloud

Las rutas de negocio usan el prefijo `/api/v1` y nombres de recursos en plural en inglés. Las operaciones nuevas usan campos públicos en camelCase, fechas UTC en ISO 8601 y estados enumerados en OpenAPI. El contrato de cada campo indica si es opcional o admite null.

Para operaciones JSON nuevas, el formato objetivo de éxito incluye `success: true`, `data` y `meta`. Las listas documentan sus parámetros, límites y metadatos de paginación, incluido el cursor siguiente cuando exista. El formato objetivo de error incluye `success: false`, `statusCode`, `error.code`, `error.message` y `meta.requestId`. Los cambios incompatibles en rutas existentes requieren una versión o transición explícita con sus consumidores. Los archivos, respuestas sin cuerpo y eventos firmados conservan su propio contrato.

| HTTP | Código de aplicación |
| --- | --- |
| 400 | BAD_REQUEST |
| 401 | UNAUTHORIZED |
| 403 | FORBIDDEN |
| 404 | RESOURCE_NOT_FOUND |
| 409 | RESOURCE_CONFLICT |
| 422 | VALIDATION_ERROR |
| 429 | RATE_LIMIT_EXCEEDED |
| 500 | INTERNAL_ERROR |
| 503 | DEPENDENCY_UNAVAILABLE |

Usar 201 para creación, 202 para trabajo aceptado que sigue pendiente, 200 para consultas o acciones con respuesta y 204 para respuestas sin cuerpo. Documentar los errores que puede devolver cada operación.

El Gateway genera `X-Request-Id` por solicitud, lo envía al servicio y lo devuelve al cliente. Notifications conserva el UUID recibido y genera uno para solicitudes directas sin un identificador válido. El identificador sirve para correlación y no concede permisos.

Los clientes se autentican con Bearer cuando la operación lo requiere. Las claves de servicio y firmas de eventos se reservan para comunicaciones internas. El análisis desde el navegador pasa por Agricultural y las compras por Identity. Los reintentos deben conservar la clave de idempotencia cuando la operación la admita. Los eventos documentan su versión y se deduplican por identificador.

Los OpenAPI locales de Identity, Agricultural y Notifications son la fuente de sus copias en `packages/contracts/openapi`. Billing e IA generan OpenAPI en ejecución. La publicación de documentación debe distinguir las operaciones públicas de las internas.
