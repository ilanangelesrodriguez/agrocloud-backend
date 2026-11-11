# 🔌 Estándar de API

## Convenciones

- **Versión:** `/api/v1`.
- **Recursos:** nombres en inglés y plural (`/lots`, `/producers`).
- **Campos nuevos:** `camelCase`; los campos heredados mantienen su contrato mientras se migren.
- **Fechas:** ISO 8601 en UTC.
- **Documentación:** `/docs` y `/openapi.json` desde Gateway.

## Autenticación

| Comunicación | Credencial |
| :-- | :-- |
| Cliente → API | `Authorization: Bearer <JWT>` |
| Cloud Run → servicio privado | `X-Serverless-Authorization` con ID token de Google |
| Comunicación interna de aplicación | Clave interna o firma HMAC, según el endpoint |

**No enviar secretos de infraestructura al frontend.** La pertenencia a una empresa y los permisos se validan en el servicio de destino.

## Respuestas

El **formato objetivo** de las nuevas operaciones JSON es:

```json
{
  "success": true,
  "data": {},
  "meta": { "requestId": "uuid" }
}
```

```json
{
  "success": false,
  "statusCode": 422,
  "error": { "code": "VALIDATION_ERROR", "message": "Datos no válidos" },
  "meta": { "requestId": "uuid" }
}
```

> Algunos endpoints existentes conservan respuestas propias. Consulta su contrato OpenAPI antes de integrarlos.

## Códigos HTTP

| Código | Significado |
| :-- | :-- |
| `200 / 201 / 202 / 204` | Correcto / creado / aceptado / sin contenido |
| `400 / 422` | Solicitud o datos inválidos |
| `401 / 403` | No autenticado / sin permiso |
| `404 / 409` | No encontrado / conflicto |
| `429` | Límite de solicitudes |
| `500 / 503` | Error interno / dependencia no disponible |

Para operaciones idempotentes conserva `Idempotency-Key` en los reintentos. `X-Request-Id` permite rastrear una solicitud entre servicios.

**Contratos:** [`packages/contracts/openapi`](../../packages/contracts/openapi/).

[← Documentación](../README.md)
