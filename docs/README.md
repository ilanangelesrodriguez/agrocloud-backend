# 📚 Documentación de AgroCloud

Guías breves para comprender, ejecutar y desplegar el backend.

| Documento | Contenido |
| :-- | :-- |
| [Arquitectura](architecture/overview.md) | Servicios, integraciones y flujo de información |
| [Estándar API](architecture/api-standard.md) | Rutas, seguridad, respuestas y errores |
| [Propiedad de datos](database/data-ownership.md) | Bases de datos y límites entre servicios |
| [Desarrollo local](local-development.md) | Preparación del entorno con Docker Compose |
| [Despliegue en Cloud Run](deployment/cloud-run.md) | Publicación, comprobaciones y seguridad |

## Accesos principales

- **Gateway:** `/` y `/health`.
- **Swagger centralizado:** `/docs`.
- **OpenAPI agregado:** `/openapi.json`.
- **Contratos versionados:** [`packages/contracts/openapi`](../packages/contracts/openapi/).

> **Importante:** la documentación describe la arquitectura y las funciones implementadas. Los flujos de negocio, pagos e inferencias requieren pruebas de integración antes de operar en producción.

[← Volver al README](../README.md)
