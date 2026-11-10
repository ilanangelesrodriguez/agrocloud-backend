# ms-identity

API Laravel 13 / PostgreSQL adaptada desde `C:\Users\ILAN\Downloads\Nube\agrocloud`. El monolito se utilizo solo como referencia de reglas; no se modifico ni se importaron sus datos. Todos los cambios de esta entrega quedan dentro de `services/ms-identity`.

## Dominio y migraciones

La nueva migracion crea empresa, rol, permiso, rol_permiso, solicitudes_acceso, auditoria de cuenta y correo, sesiones de API, ordenes esperadas, suscripciones, derechos y registros idempotentes de eventos/consumo. Renombra la tabla inicial `users` a `usuario` y sus columnas a id_usuario, nombre, correo y password_hash, preservando sus filas e IDs y normalizando correo a minusculas sin espacios. Las colisiones entre correos normalizados detienen la migracion para su conciliacion manual. Las cuentas iniciales sin empresa o rol no obtienen acceso automaticamente. Esta migracion corresponde al esqueleto actual: no es un importador ni debe aplicarse sobre las tablas del monolito.

Los modelos conservan IDs numericos y las relaciones internas PostgreSQL. Cada usuario tiene una empresa y un rol; solo SUPERADMIN de plataforma puede tener empresa nula. Los permisos agricolas permanecen como catalogo de autorizacion, sin importar tablas ni servicios agricolas. No se crean cuentas predeterminadas ni contrasenas conocidas en los seeds de produccion.

## Autenticacion y seguridad

- Login con correo y password; la cuenta debe estar activa, verificada y tener rol y empresa habilitados. Responde access_token RS256, expires_in y refresh_token.
- Acceso de 15 minutos por defecto (maximo 30), con iss, aud, sub, iat, nbf, exp, jti, sid, company_id, role, permissions y version de autenticacion. La clave publica se publica en `/.well-known/jwks.json` y `/api/v1/auth/keys`.
- Refresh de 14 dias por defecto, con rotacion de secretos guardados como SHA-256. Reutilizar un refresh consumido revoca toda su sesion. El cliente debe serializar renovaciones para no interpretar dos renovaciones simultaneas como replay.
- Identity comprueba estado actual y revocacion en cada solicitud. Los demas servicios que verifiquen JWT sin consulta pueden aceptar un token hasta su vencimiento; para revocacion inmediata deben usar introspeccion. No se modificaron esos servicios.
- Empresas y usuarios se filtran por la empresa del actor, sin confiar en cabeceras de empresa. No se permite reasignar empresa ni asignar SUPERADMIN desde la API de usuarios. Los cambios de cuenta revocan sesiones; no se permite quitar el ultimo ADMIN activo.
- Invitaciones: secreto hasheado, vencimiento 72 horas, un solo uso. El administrador crea la cuenta sin conocer su contrasena; el invitado la elige al activar.
- Recuperacion con respuesta publica neutral y broker Laravel sobre correo/password_hash. Restablecer contrasena revoca todas las sesiones.
- Cambiar correo exige la contrasena actual y confirmar el nuevo destino dentro de 24 horas. Hasta confirmar sigue vigente el correo anterior; la confirmacion revoca sesiones y notifica al correo anterior.
- Los avisos de seguridad no dependen de preferencias operativas. Los trabajos de correo se cifran con APP_KEY y registran intentos; provider_accepted no significa entrega confirmada. Los transportes log/array/failover se rechazan para no registrar secretos como si se hubieran enviado.
- Se aplican limites de solicitudes, respuestas JSON y Cache-Control no-store en la API. Las claves internas no se incluyen en el frontend.

## Endpoints

Prefijo `/api/v1`.

| Grupo | Rutas |
| --- | --- |
| Salud | GET health, ready; GET /up fuera del prefijo |
| Acceso publico | POST auth/login, auth/refresh, auth/forgot-password, auth/reset-password, auth/activate, auth/confirm-email; GET auth/keys |
| Cuenta autenticada | GET auth/me; POST auth/logout, auth/logout-all, auth/change-email |
| Empresas | GET/POST companies; GET/PATCH companies/{company} |
| Usuarios | GET/POST users; GET/PATCH users/{user}; POST users/{user}/invitation; GET users/{user}/security |
| Seguridad | GET roles, permissions |
| Solicitudes | POST access-requests publico; GET access-requests y POST access-requests/{accessRequest}/decision solo SUPERADMIN |
| Suscripcion | GET subscription; POST subscription/orders con offer_key |
| Interno | POST internal/billing/events; POST internal/auth/introspect; GET internal/companies/{company}/recipients; GET internal/companies/{company}/entitlements; POST internal/companies/{company}/usage |

Las rutas internas usan X-Service-Key, salvo el evento de Billing que usa HMAC del cuerpo crudo. Introspeccion requiere ademas Authorization: Bearer. Recipients exige `permission` y pagina resultados; no devuelve usuarios inactivos o de otra empresa. El Gateway actual ya bloquea rutas internas; deben seguir fuera del acceso publico en cualquier despliegue.

`openapi.yaml` contiene el contrato local de esta API. No se actualizo el contrato compartido fuera de este servicio por el alcance solicitado.

## Billing y derechos de acceso

`IDENTITY_BILLING_OFFERS` es un objeto JSON de ofertas aprobadas; por defecto esta vacio y no permite checkout. No se asignan precios ni cupos comerciales ficticios. Cada oferta tiene kind y amount_cents (100..999900 PEN). PLAN/RENEWAL requieren plan, duration_days (1..366), y limits con usuarios, lotes, analisis_ia enteros. Los addons USER_ADDON/LOT_ADDON/AI_ADDON requieren quantity positivo. Ofertas de renovacion deben coincidir con el plan existente.

`POST subscription/orders` recibe solo offer_key, toma empresa y correo del actor, guarda la oferta y precio esperados y llama al API interno de Billing con una referencia numerica-compatible e idempotente. Solo se permite una orden pendiente por empresa: repetir la misma oferta reutiliza su referencia y el correo capturado. Ante abandono o rechazo permanente de Billing se requiere conciliacion administrativa de la orden; no hay cancelacion automatica de pagos en vuelo.

El receptor `POST internal/billing/events` valida X-AgroCloud-Signature (`sha256=` + HMAC SHA-256 del cuerpo original), X-Event-Id y el payload actual de payment.succeeded. Exige una orden esperada con empresa, clase, importe y moneda coincidentes, y provider_charge_id no utilizado. Deduccion, actualizacion de derechos y registro de evento se realizan en una transaccion con bloqueo por empresa. El mismo evento exacto devuelve duplicate=true; reutilizar ID con otro cuerpo devuelve 409. Firmar un evento no permite inventar una compra desconocida.

PLAN inicia un periodo y cupos nuevos. RENEWAL de una suscripcion vigente extiende desde el vencimiento y agrega los creditos consumibles del nuevo periodo sin borrar lo ya consumido; los puestos de usuario son simultaneos, no se acumulan por renovacion. Una renovacion vencida inicia desde ahora y reinicia contadores. Los addons requieren una suscripcion activa y aumentan el recurso comprado hasta su vencimiento. Esta convencion debe revisarse con las condiciones comerciales antes de operar cobros reales.

`internal/companies/{company}/usage` recibe request_id, recurso (lotes/analisis_ia), cantidad. Consume cupo atomicamente e idempotentemente; repetir ID con otros parametros devuelve 409. No coordina una transaccion distribuida con Agricultural: el consumidor debe definir recuperacion/compensacion si su operacion falla. Los puestos de usuario se comprueban al crear cuentas y cuentan todas las cuentas existentes, incluidas pendientes. Se permite el primer ADMIN al aprovisionar una empresa para que pueda contratar un plan.

Configurar en Billing AGROCLOUD_EVENT_URL con `http://ms-identity:8000/api/v1/internal/billing/events` para desarrollo y el mismo AGROCLOUD_EVENT_SECRET. Este trabajo no modifica el entorno de Billing. El proveedor RUC se conserva como consulta configurable HTTPS, con timeout y cache; si no esta disponible queda pendiente, nunca se inventa verificacion SUNAT.

## Preparacion manual (comandos no ejecutados)

1. Completar `.env` del servicio con APP_KEY, PostgreSQL, IDENTITY_SERVICE_API_KEY y las variables de Billing, frontend y correo. Usar claves internas aleatorias de al menos 32 caracteres. Para produccion usar HTTPS y desactivar APP_DEBUG.
2. Instalar con `composer install`; generar claves con `php artisan identity:keys` y conservarlas fuera de Git. El comando no sobrescribe claves existentes. Definir IDENTITY_KEY_ID distinto al rotar claves y coordinar los consumidores; esta version publica una sola clave activa.
3. Revisar y respaldar la base destino antes de `php artisan migrate`. Despues ejecutar `php artisan db:seed`. Para crear el administrador inicial configurar temporalmente IDENTITY_BOOTSTRAP_EMAIL e IDENTITY_BOOTSTRAP_PASSWORD y retirarlos despues. Repetir el seeder no cambia su contrasena ni convierte otra cuenta en SUPERADMIN.
4. Configurar SMTP real y ejecutar `php artisan queue:work --tries=3`. Sin worker no salen recuperaciones, invitaciones ni cambios de correo. El frontend debe implementar las pantallas activate, reset-password y confirm-email que reciben el enlace y hacen POST a la API.
5. Para Docker, usar desde la raiz el overlay `-f compose.yaml -f services/ms-identity/compose.identity.yaml`. Extiende solo Identity y agrega un worker opcional con perfil `identity-mail`. Pasar las variables nuevas en el entorno de Compose o su `.env` raiz: Compose no lee automaticamente el `.env` del servicio. MAIL_* del overlay se alimenta de IDENTITY_MAIL_HOST/PORT/SCHEME/USERNAME/PASSWORD/FROM_ADDRESS. Para Docker generar las claves dentro del volumen identity-keys, sin copiar PEM al contexto de construccion del monorepo; ejecutar la generacion de claves y las migraciones/seed dentro de ese entorno antes del login. En produccion montar secretos de solo lectura y usar un servidor PHP adecuado; el servidor incluido es de desarrollo.

Readiness ahora exige base accesible, esquema de Identity y par RSA coincidente. Liveness no garantiza correo, Billing o configuracion comercial. No se construyeron imagenes, levantaron contenedores, ejecutaron migraciones, enviaron correos ni ejecutaron tests/compilaciones durante esta entrega. La validacion realizada es estatica: sintaxis, manifiestos, rutas y formato.
