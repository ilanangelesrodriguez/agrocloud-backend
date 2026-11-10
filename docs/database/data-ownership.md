# Propiedad de datos

| Servicio | Base / usuario local | Propiedad |
| --- | --- | --- |
| Identity | identity_db / identity_app | Usuarios, empresas, credenciales y suscripciones (modelo pendiente) |
| Agricultural | agricultural_db / agricultural_app | Productores, lotes, inspecciones y dictámenes (modelo pendiente) |
| IA | ai_db / ai_app | Ejecuciones y detecciones técnicas; sin dictámenes agrícolas |
| Billing | MongoDB agrocloud_billing | Órdenes, pagos, reembolsos, webhooks y outbox |
| Notifications | notifications_db / notifications_app | Reserva para bandeja, preferencias y entregas; acceso a datos aún no implementado |

Cada aplicación debe usar su usuario, nunca el administrador PostgreSQL. El script inicial revoca acceso público a cada base. No se permiten consultas cruzadas ni claves foráneas entre servicios: los IDs externos y contratos definen las relaciones.

Agricultural conserva las migraciones iniciales de Laravel como antecedente. No debe usar ese users para autenticación independiente; antes de sus primeras migraciones de dominio hay que revisar si existen instalaciones con datos y planificar su sustitución. No se eliminó ninguna tabla.

Billing usa companyId numérico actualmente. Identity deberá conservar compatibilidad o introducir un identificador público con migración explícita. Notifications guardará referencias a destinatarios, sin copiar credenciales ni crear cuentas propias.
