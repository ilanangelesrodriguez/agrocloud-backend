# 🗄️ Propiedad de datos

Cada microservicio es **responsable de sus propias tablas y operaciones**. Las referencias entre dominios se resuelven mediante identificadores y APIs, no mediante claves foráneas entre bases.

| Servicio | Persistencia | Datos que administra |
| :-- | :-- | :-- |
| **Identity** | PostgreSQL (`identity_db`) | Empresas, usuarios, sesiones, roles, planes y cupos |
| **Agricultural** | PostgreSQL (`agricultural_db`) | Productores, lotes, muestras, inspecciones, evidencias y dictámenes |
| **Notifications** | PostgreSQL (`notifications_db`) | Notificaciones, preferencias, eventos y entregas |
| **AI** | PostgreSQL (`ai_db`) | Inferencias y detecciones técnicas |
| **Billing** | MongoDB (`agrocloud_billing`) | Órdenes, pagos, webhooks, reembolsos y outbox |

En Cloud Run, las bases PostgreSQL se conectan a **Neon** y Billing utiliza **MongoDB Atlas**.

## Reglas de diseño

- **Identity** es la fuente de usuarios y pertenencia a empresas.
- **Agricultural** conserva las decisiones humanas; AI solo entrega resultados técnicos.
- **Billing** registra pagos; Identity aplica las suscripciones mediante eventos verificados.
- Las migraciones pertenecen a cada servicio: **Laravel**, migraciones de **Notifications** y **Alembic** para AI.
- Las imágenes y claves RSA requieren almacenamiento privado; no deben incluirse en Git ni en imágenes Docker.

> Antes de migrar, verifica el destino y realiza respaldos. No ejecutes migraciones destructivas para corregir errores de configuración.

[← Documentación](../README.md)
