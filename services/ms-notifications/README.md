# ms-notifications

API NestJS 12 / PostgreSQL para bandeja empresarial, preferencias, eventos y entregas. Completa la base parcial de TypeORM y correo que ya estaba presente. El monolito `C:\Users\ILAN\Downloads\Nube\agrocloud` se uso como referencia; no se modifico ni se importaron sus datos. No se crean usuarios ni empresas locales.

## Persistencia y limites del servicio

Se mantiene TypeORM como unica capa de conexion y migraciones, sin synchronize ni migraciones automaticas al arrancar. La migracion inicial crea notifications, notification_preferences, notification_deliveries y processed_events; notification_rate_limits soporta limites compartidos entre instancias. recipient_id y company_id son referencias externas sin FK a Identity. Las entregas si tienen una FK local a la notificacion.

Los avisos guardan IDs, contenido y permiso de elegibilidad; no se copia el directorio de usuarios ni se conserva su correo para entregas futuras. El worker consulta el correo vigente de Identity antes de cada envio.

## Autenticacion, bandeja y preferencias

Las rutas de usuario exigen Authorization: Bearer e introspeccion en Identity con X-Service-Key. Se comprueba cuenta activa, activacion completada y empresa positiva. Una caida de Identity no concede acceso. La bandeja es empresarial: los avisos de plataforma sin empresa requieren un contrato adicional; no se convierte companyId=0 en una empresa valida.

Todas las consultas y actualizaciones de bandeja usan el usuario y la empresa autenticados. No se admiten recipient_id/company_id elegidos desde React. Los avisos operativos y de Billing tambien requieren conservar el permiso de elegibilidad; los avisos personales de seguridad siguen accesibles al propietario activo. Leer un UUID ajeno devuelve 404. Las preferencias solo modifican la cuenta propia.

| Endpoint | Funcion |
| --- | --- |
| GET /health | Liveness del proceso |
| GET /ready | Conexion y tablas principales; no consulta SMTP o Identity |
| GET /api/v1/notifications | Bandeja con limit (1..100), cursor y unread=true/false |
| GET /api/v1/notifications/unread-count | Contador propio |
| PATCH /api/v1/notifications/{id}/read | Lectura idempotente de un aviso propio |
| PATCH /api/v1/notifications/read-all | Marcar la bandeja visible como leida |
| GET /api/v1/notification-preferences | Preferencias y lista de tipos opcionales |
| PUT /api/v1/notification-preferences | Reemplazar mutedKinds y emailEnabled |

El Gateway expone la bandeja en /api/v1/notifications y las preferencias en /api/v1/notifications/preferences. Conserva las rutas anteriores con doble notifications y notification-preferences por compatibilidad.

Se conserva GET / del esqueleto por compatibilidad. OpenAPI esta en openapi.yaml. El frontend puede hacer polling de la primera pagina y el contador; no se implementaron WebSocket/SSE.

PUT de preferencias recibe un objeto completo, por ejemplo `{"mutedKinds":["LOTE_RECIBIDO"],"emailEnabled":false}`. Solo admite los tipos opcionales del monolito: LOTE_REGISTRADO, LOTE_RECIBIDO, INSPECCION_ASIGNADA, INSPECCION_VALIDADA y DICTAMEN_APROBADO. El silenciamiento aplica a avisos de prioridad normal; no suprime avisos altos/criticos. Los avisos de categoria security fuerzan bandeja y cola de correo, independientemente de las preferencias. Desactivar MAIL_ENABLED conserva las entregas pendientes, no simula su envio.

## Recepcion firmada y procesamiento persistente

| Endpoint interno | Contrato / clave |
| --- | --- |
| POST /api/v1/internal/events/agricultural | Eventos de Agricultural v1; AGRICULTURAL_EVENT_SECRET |
| POST /api/v1/internal/events/identity | account.security_notice v1; IDENTITY_EVENT_SECRET |
| POST /api/v1/internal/events/billing | payment.succeeded actual; BILLING_EVENT_SECRET |

Cada fuente usa su secreto de 32+ caracteres. Vacio desactiva esa fuente. Se exige X-Event-Id y X-AgroCloud-Signature (`sha256=` + HMAC SHA-256 del cuerpo JSON original, sin reserializarlo). Se limita JSON a 64 KiB. No se acepta un destino, titulo, mensaje, permiso arbitrario o credencial de recuperacion enviado desde React.

Un 202 significa que el evento esta persistido. La clave de deduplicacion es (source,event_id). Repetir el cuerpo exacto devuelve duplicate=true; reutilizar ID con otro cuerpo devuelve 409. El fanout se procesa despues, consulta destinatarios activos y autorizados en Identity, aplica preferencias y crea avisos y entregas en una transaccion con la finalizacion del evento. El limite MAX_EVENT_RECIPIENTS falla el trabajo completo: no se trunca silenciosamente una lista.

La politica de cada evento define permisos de destinatarios en el servidor. Las sugerencias recipientPermissions del productor no pueden ampliar esa politica. Los recipientIds explicitos se intersectan con usuarios autorizados de la empresa. Se usa el contrato disponible de Identity basado en permisos; no se copian ni se consultan sus tablas para reproducir un directorio de roles.

Agricultural ya publica lot.created, lot.cancelled, lot.received, inspection.validated, quality.decision.issued, incident.opened, incident.updated y evidence.analysis_failed. Configurar en su entorno NOTIFICATIONS_EVENT_URL=http://ms-notifications:3000/api/v1/internal/events/agricultural y el mismo AGRICULTURAL_EVENT_SECRET. Este trabajo no modifica el entorno ni el codigo de Agricultural; activar la configuracion corresponde a la puesta en marcha.

Identity y Billing tienen receptores preparados, pero necesitan productores o fanout confiable. Identity actualmente envia invitaciones, recuperaciones y cambios de correo desde su propio servicio: los tokens permanecen alli. El contrato adicional account.security_notice recibe solo recipientIds y un code permitido, sin enlaces secretos ni texto libre. Billing tiene actualmente un destino de outbox hacia Identity: no sustituirlo por Notifications, porque Identity debe procesar la suscripcion. Para avisar tambien a Notifications se necesita fanout o un evento posterior desde Identity.

Los contratos locales estan en contracts/. Los destinos de navegacion son rutas relativas elegidas por el servidor (/lotes/{id}, /suscripcion, /notificaciones). El frontend debe implementar o mapear esas rutas; el API no redirige a URLs suministradas por clientes.

## Worker y correo

`npm run start:worker -w @agrocloud/ms-notifications` ejecuta el worker compilado; necesita dist/worker.js ya preparado. El API no ejecuta tareas ni envia correos al recibir HTTP. El worker procesa eventos y entregas con FOR UPDATE SKIP LOCKED, leases y token de propietario; se puede ejecutar en varias instancias. Los leases expirados se recuperan incluso tras una caida en el ultimo intento.

Hay hasta ocho intentos con espera creciente; los fallos terminales quedan FAILED. Los avisos a destinatarios que ya no estan activos, cambiaron de empresa/permisos o dejaron de aceptar el canal se cancelan antes del envio. Los errores transitorios de Identity conservan el trabajo para reintentar.

MAIL_ENABLED=false procesa la bandeja y retiene las entregas. Con true se requiere SMTP_URL y MAIL_FROM. Se usa @nestjs/mail y SMTP, TLS obligatorio en produccion, timeout de envio y texto plano sin HTML de productores. El ID de entrega se usa como clave idempotente/Message-ID estable. SMTP no garantiza exactamente una entrega: si el proveedor acepta y se pierde la respuesta, un reintento puede producir un duplicado. PROVIDER_ACCEPTED significa aceptacion por el servidor, no recepcion final en la bandeja del usuario.

## Operacion interna

X-Notifications-Operator-Key, separado de los JWT y secretos de productores, protege:

- GET /api/v1/internal/operations/status: conteos por estado, sin cuerpos o direcciones de correo.
- POST /api/v1/internal/operations/events/{source}/{eventId}/retry: reactivar solo un evento FAILED y desbloqueado.
- POST /api/v1/internal/operations/deliveries/{id}/retry: reactivar solo una entrega FAILED y desbloqueada.

No se reenvian entregas PROVIDER_ACCEPTED desde esos endpoints. El Gateway existente bloquea rutas internal; mantenerlas privadas en cualquier despliegue. Los codigos de error persistidos son genericos para evitar guardar credenciales o cuerpos en logs. El worker limpia las ventanas antiguas del rate limiter; no borra la bandeja ni los registros de entrega.

## Preparacion y validacion

1. Completar .env con PostgreSQL propio y las credenciales de Identity. Para ejecucion manual usar hosts/puertos locales; para Compose usar DNS de contenedores. En DATABASE_URL escapar las credenciales. El overlay espera contrasenas alfanumericas porque interpola la URL.
2. Instalar dependencias Node desde la raiz del monorepo con su lockfile. No crear un lockfile anidado.
3. Revisar la migracion y ejecutarla explicitamente con `npm run migrate -w @agrocloud/ms-notifications`. El script usa una conexion dedicada para su bloqueo de migracion y transaccion para los cambios. El rollback automatico se rechaza para conservar la bandeja y el historial; requiere una migracion revisada. No ejecutar este esquema sobre la base del monolito.
4. Para Docker usar compose.yaml raiz: incluye Dockerfile propio, API y notifications-worker sin perfil adicional. Aplicar primero node scripts/migrate.mjs como indica el README raiz; el contenedor no aplica DDL al arrancar.
5. El overlay se conserva como override opcional. Compose usa .env raiz, no carga automaticamente el .env del servicio. El correo usa NOTIFICATIONS_MAIL_ENABLED, NOTIFICATIONS_SMTP_URL y NOTIFICATIONS_MAIL_FROM; el frontend usa NOTIFICATIONS_FRONTEND_URL. El entorno local permite HTTP entre contenedores; produccion requiere HTTPS y SMTP seguro.

Se conservaron las dependencias y cambios parciales existentes, incluido el lockfile raiz previo. No se ejecutaron tests, compilaciones, migraciones, workers, contenedores ni envios de correo. La revision realizada es estatica: formato, lint sin comprobacion de tipos, sintaxis de scripts y contratos. El flujo integrado y las migraciones requieren verificacion posterior con los servicios configurados.
