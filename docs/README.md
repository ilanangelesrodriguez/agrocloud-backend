# 📚 Documentación de AgroCloud

Guías breves para comprender, ejecutar y desplegar el backend.

| Documento | Contenido |
| :-- | :-- |
| [Arquitectura](architecture/overview.md) | Microservicios e integraciones |
| [Estándar API](architecture/api-standard.md) | Rutas, seguridad, respuestas y errores |
| [Propiedad de datos](database/data-ownership.md) | Bases de datos y límites de cada servicio |
| [Desarrollo local](local-development.md) | Docker Compose, claves y migraciones |
| [Cloud Run](deployment/cloud-run.md) | Publicación, pruebas y seguridad |
| [Documentación de AI](../services/ms-ai/docs/README.md) | Swagger, arquitectura, integración HTTP y despliegue de IA |

## Accesos principales

| Ruta de Gateway | Función |
| :-- | :-- |
| `/` | Descripción de los servicios |
| `/health` | Estado del Gateway |
| `/docs` | Swagger centralizado |
| `/openapi.json` | Especificación OpenAPI agregada |

Los contratos versionados están en [`packages/contracts/openapi`](../packages/contracts/openapi/).

> **Nota:** un endpoint `/health` o `/ready` en `200` no demuestra que los flujos de negocio, pagos o inferencias funcionen de extremo a extremo.

[← Volver al proyecto](../README.md)
