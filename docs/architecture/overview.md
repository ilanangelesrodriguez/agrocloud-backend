# Arquitectura actual

El Gateway recibe tráfico en 4000 y enruta por servicio hacia APIs versionadas. Cada servicio valida sus propias credenciales; la autenticación central y la pertenencia a empresa siguen pendientes. No deben publicarse datos de negocio sin esos controles.

Identity y Agricultural son esqueletos Laravel con API de estado y configuración PostgreSQL. Billing conserva órdenes, pagos, reembolsos, webhooks y outbox sobre MongoDB. IA conserva inferencia, PostgreSQL/Alembic y almacenamiento Cloudinary. Notifications sigue siendo un esqueleto con liveness.

Compose raíz es la entrada de desarrollo. Los Dockerfiles Node usan el lockfile raíz; Laravel comparte una receta parametrizada; IA conserva su Dockerfile. PostgreSQL y MongoDB permanecen sin puertos publicados. No hay Redis ni broker: no existen consumidores que los requieran aún.

Pendientes de integración: autenticación de Identity y multiempresa; entidades agrícolas; contrato Agricultural-IA; receptor firmado e idempotente Billing-Identity; persistencia y worker de Notifications; validación dinámica y despliegue de producción. No se agregaron endpoints ficticios para esas funciones.

Los contratos OpenAPI de estado son implementados; el evento notification.requested es una propuesta. Un cambio de companyId numérico a UUID requiere una migración coordinada y no forma parte de esta preparación.

El perfil Compose `events` agrega un worker de outbox independiente. Requiere URL de receptor y desactivar el temporizador del proceso HTTP con BILLING_OUTBOX_TIMER_ENABLED=false.
