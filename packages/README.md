<div align="center">

# 📦 Shared Packages

**Contratos versionados para mantener consistentes las APIs y los eventos de AgroCloud.**

![OpenAPI](https://img.shields.io/badge/OpenAPI-3.x-6BA539?logo=swagger&logoColor=white)
![JSON Schema](https://img.shields.io/badge/JSON_Schema-Eventos-292929?logo=json&logoColor=white)

[← Proyecto](../README.md) · [Gateway](../gateway/README.md) · [Estándar API](../docs/architecture/api-standard.md)

</div>

---

## Estructura

```text
packages/
└── contracts/
    ├── openapi/
    │   ├── identity.yaml
    │   ├── agricultural.yaml
    │   ├── notifications.yaml
    │   ├── billing.yaml
    │   └── ai.yaml
    └── events/
        ├── notification.requested.schema.json
        └── payment.succeeded.schema.json
```

## Contratos HTTP

| Archivo | Alcance |
| :-- | :-- |
| `identity.yaml` | Autenticación, empresas, usuarios y suscripciones. |
| `agricultural.yaml` | Productores, lotes, inspecciones, evidencias y calidad. |
| `notifications.yaml` | Bandeja, preferencias y eventos del servicio. |
| `billing.yaml` | Estado y disponibilidad publicados a través del Gateway. |
| `ai.yaml` | Estado y disponibilidad publicados a través del Gateway. |

Los archivos `.yaml` actuales contienen **JSON válido**. Gateway los carga para construir su `/openapi.json` y mostrar Swagger UI en `/docs`.

> El contrato compartido **documenta** operaciones; no reemplaza la autenticación ni las verificaciones de permisos. Billing y AI mantienen sus OpenAPI propios para operaciones técnicas completas.

## Contratos de eventos

- **`payment.succeeded`**: estructura del evento de pago confirmado.
- **`notification.requested`**: estructura de una solicitud de notificación.

Los servicios validan sus propias firmas, permisos e idempotencia. Un esquema no autoriza a un cliente externo a publicar eventos internos.

## Cómo mantenerlos actualizados

1. Modifica primero la ruta o evento en el servicio propietario.
2. Actualiza el contrato correspondiente y conserva su compatibilidad.
3. Sincroniza las copias compartidas con los contratos del servicio.
4. Reconstruye **Gateway** si cambia un archivo de `contracts/openapi/`: estos contratos se copian dentro de su imagen Docker.

Consulta el [estándar de la API](../docs/architecture/api-standard.md) para convenciones de rutas, errores y versiones.

---

<sub>AgroCloud · Contratos compartidos</sub>
