<div align="center">

# 💳 AgroCloud Billing

**Órdenes, pagos y conciliación con Culqi para los planes de AgroCloud.**

![NestJS](https://img.shields.io/badge/NestJS-12-E0234E?style=flat-square&logo=nestjs&logoColor=white)
![Node.js](https://img.shields.io/badge/Node.js-22-339933?style=flat-square&logo=nodedotjs&logoColor=white)
![MongoDB](https://img.shields.io/badge/Base_de_datos-MongoDB-47A248?style=flat-square&logo=mongodb&logoColor=white)
![Culqi](https://img.shields.io/badge/Pagos-Culqi-2563EB?style=flat-square)

[**Servicios**](../README.md) · [**Gateway**](../../gateway/README.md) · [**Arquitectura**](../../docs/architecture/overview.md)

</div>

---

## 💰 Responsabilidades

- Crear órdenes y registrar pagos en **MongoDB**.
- Procesar cargos mediante Culqi y consultar su estado.
- Conciliar pagos inciertos y gestionar solicitudes de reembolso.
- Recibir webhooks, deduplicarlos y emitir eventos firmados a Identity.

## 🔄 Flujo de pago

```mermaid
flowchart LR
    U[Usuario] --> I[Identity]
    I --> B[Billing]
    B --> C[Culqi]
    C -. Webhook .-> B
    B -. payment.succeeded .-> I
    I --> P[Suscripción]
```

**Identity inicia la compra**; Billing es un backend privado y no expone claves de Culqi al navegador.

## 📡 Rutas principales (servicio directo)

| Método | Ruta | Propósito |
| --- | --- | --- |
| `POST` | `/api/v1/orders` | Crear orden |
| `POST` | `/api/v1/payments/charges` | Solicitar cargo |
| `GET` | `/api/v1/payments/{reference}` | Consultar pago |
| `POST` | `/api/v1/payments/{reference}/reconcile` | Conciliar pago |
| `POST` | `/api/v1/webhooks/culqi/{token}` | Recibir evento Culqi |
| `GET` | `/health` | Estado del proceso |
| `GET` | `/ready` | Estado de MongoDB |

**Swagger del servicio:** `/docs` · **OpenAPI:** `/openapi.json` cuando `SWAGGER_ENABLED=true`.

> En el Swagger **central de Gateway** solo se documentan las rutas de estado de Billing. Las operaciones financieras deben ser llamadas desde servicios autorizados.

## 🛠️ Desarrollo

Desde la raíz del monorepo:

```bash
npm ci
npm run dev:billing
```

Antes, prepara [`.env.example`](.env.example) con `MONGODB_URI`, claves Culqi **de prueba**, `SERVICE_API_KEY` y secretos de eventos. Para el entorno integrado, sigue [Docker Compose](../../docs/local-development.md).

## 🔐 Seguridad y despliegue

- Comienza con `CULQI_MODE=test`; no confundas un cargo registrado con una suscripción activada.
- Verifica el webhook contra Culqi y entrega eventos idempotentes a Identity.
- En Cloud Run, configura un receptor HTTPS controlado para Culqi y un despachador de outbox; el temporizador no garantiza ejecución con escalado a cero.
- No pongas `SERVICE_API_KEY`, `CULQI_SECRET_KEY` ni tokens de webhook en frontend, Git o Swagger público.

**Más información:** [Estándar API](../../docs/architecture/api-standard.md) · [Cloud Run](../../docs/deployment/cloud-run.md)
