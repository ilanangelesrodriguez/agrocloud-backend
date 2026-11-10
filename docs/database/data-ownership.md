# Propiedad de datos

| Servicio | Base / usuario local | Propiedad |
| --- | --- | --- |
| Identity | identity_db / identity_app | Usuarios, empresas, credenciales, sesiones, permisos, suscripciones, cupos y eventos de pagos recibidos |
| Agricultural | agricultural_db / agricultural_app | Productores, lotes, recepciones, muestreos, inspecciones, evidencias, dictamenes, incidencias, trabajos IA y outbox |
| IA | ai_db / ai_app | Ejecuciones y detecciones tecnicas; el dictamen pertenece a Agricultural |
| Billing | MongoDB agrocloud_billing | Ordenes, pagos, reembolsos, webhooks y outbox |
| Notifications | notifications_db / notifications_app | Eventos recibidos, bandeja, preferencias, entregas y reintentos |

Cada aplicacion usa su usuario, nunca el administrador PostgreSQL. El script inicial revoca acceso publico a cada base. Las relaciones externas usan IDs y contratos HTTP/eventos; no se crean consultas cruzadas ni claves foraneas entre servicios.

Identity es la autoridad de usuarios y pertenencia a empresas. Agricultural y Notifications validan actores y referencias mediante Identity, sin mantener credenciales propias de usuarios. Billing conserva companyId numerico compatible con el contrato de Identity.

identity-keys conserva las claves RSA privadas de Identity. agricultural-evidence conserva los originales privados y se comparte con el worker agricola. No guardar estos datos en Git ni en imagenes Docker. Las copias de seguridad deben incluir bases y volumenes persistentes.

Migraciones y catalogos se aprovisionan explicitamente antes de arrancar workers; no se borran tablas existentes para reconciliar configuraciones. Cambiar contrasenas del entorno no modifica usuarios PostgreSQL inicializados previamente.
