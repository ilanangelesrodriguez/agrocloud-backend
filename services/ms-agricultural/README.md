# ms-agricultural

API Laravel 13 / PostgreSQL, adaptada desde `C:\Users\ILAN\Downloads\Nube\agrocloud`. Solo se modifica este servicio. No se trasladan Blade/Livewire, tablas de empresa/usuario ni relaciones Eloquent hacia Identity. No se importaron datos del monolito.

## Alcance implementado

Productores y consulta RUC configurable; catalogos compartidos y configuracion por empresa; procedimientos de muestreo versionados; lotes; recepcion fisica; muestreo y frutos identificados; inspecciones; evidencias privadas; analisis de IA asincrono; decisiones humanas por deteccion y hallazgos manuales; revision explicita de cada fotografia; validacion de inspeccion; dictamenes sucesivos; incidencias con seguimiento; trazabilidad y resumen operativo. Los cambios y sus eventos de notificacion se persisten en la misma transaccion local.

## Identidad y aislamiento

Cada solicitud de negocio exige Authorization: Bearer. Agricultural llama a `POST /api/v1/internal/auth/introspect` de Identity con X-Service-Key. Identity valida firma, expiracion, audiencia, emisor, sesion, usuario, empresa y permisos; Agricultural valida su respuesta y exige empresa positiva. No hay cache local de identidad: la indisponibilidad de Identity devuelve 503 y no concede acceso. El SUPERADMIN sin empresa no puede operar como una empresa arbitraria.

El contexto de empresa se fija desde la identidad autenticada; las cabeceras o campos de empresa enviados por el cliente no lo sustituyen. Los modelos agricolas tienen un filtro global que falla sin contexto y rechazan reasignaciones de empresa. Los trabajadores establecen y restauran contexto explicito por trabajo. Las relaciones internas usan claves foraneas compuestas con id_empresa; los IDs de usuarios, responsables, inspectores y supervisores son referencias externas sin FK ni copias de cuentas.

La migracion conserva los nombres principales del dominio original. Las tablas iniciales users, sessions y password_reset_tokens se renombran a legacy_auth_* para apartarlas sin borrar posibles datos previos. No existe proveedor de autenticacion local, modelo User ni endpoints de cuentas. Estas migraciones son para el esqueleto actual de Agricultural, no para ejecutarlas sobre la base del monolito. Revisar y respaldar la base destino antes de aplicarlas.

## Flujo y reglas conservadas

1. ADMIN configura catalogos y procedimientos. Las versiones de procedimiento son inmutables; solo se habilitan/deshabilitan y las muestras guardan su snapshot. El seed incluye los catalogos agricolas basicos del monolito, sin inventar reglas de calidad ni cuentas de usuarios.
2. Crear lote con permiso lotes.crear y un Idempotency-Key UUID. Siempre inicia PENDIENTE_RECEPCION; productor debe ser de la empresa. Pesos se convierten y almacenan en kg. Las fechas de ingreso usan ISO 8601 con zona y se guardan en UTC; la fecha prevista no se sobrescribe al recibir.
3. Solo RECEPCION con permiso recepciones.crear confirma una unica recepcion. Exige peso positivo y explicar diferencias con el declarado. La restriccion unica y el bloqueo del lote impiden recepciones duplicadas.
4. Solo INSPECTOR crea un muestreo tras la recepcion. Exige procedimiento autorizado, instrucciones confirmadas, limite de frutos, justificacion de desviaciones y poblacion cuando el procedimiento la requiere. MANUAL_SAMPLING_ENABLED=false por defecto: una excepcion manual, si se habilita, exige protocolo, metodo, criterio, seleccion y motivo. Muestras mayores al umbral requieren confirmar la carga. PostgreSQL genera los frutos mediante generate_series en una insercion.
5. Solo hay una inspeccion EN_CAPTURA por muestra. Un inspector distinto no modifica una inspeccion asignada. Una reinspeccion solo se abre desde el dictamen del supervisor y conserva el expediente anterior.
6. Cada fruto necesita al menos una foto pertinente. Se admiten JPEG/PNG/WebP de hasta 3 MiB y 16 millones de pixeles. Los originales quedan privados y se descargan mediante `GET /api/v1/evidences/{id}/image`; la ruta fisica no aparece en JSON. Una foto descartada no se elimina: conserva resultados e historial y exige reemplazo para cubrir su fruto.
7. Las detecciones de IA son datos tecnicos inmutables. El inspector confirma/descarta cada una y registra omisiones como hallazgos manuales. Para revisar una fotografia debe decidir todas sus detecciones y confirmar pertinencia y revision completa; sin IA completada se exige justificacion manual. La huella de revision invalida conclusiones cuando cambia el resultado o los hallazgos.
8. Finalizar exige cobertura de todos los frutos y revision vigente de todas las fotos activas; no acepta analisis en cola o ejecutandose. Cambia a VALIDADA y pendiente de dictamen.
9. Solo SUPERVISOR con calidad.clasificar puede dictaminar y nunca su propia inspeccion. Una aprobacion exige expediente completo y ausencia de incidencias ALTA/CRITICA sin cerrar. DOCUMENTACION/CORRECCION_ADMINISTRATIVA permiten un nuevo dictamen con referencia al ultimo y respuesta documentada; nunca se actualiza ni borra el anterior. Una observacion REINSPECCION abre otra inspeccion.
10. Incidencias: ABIERTA -> EN_ATENCION -> RESUELTA -> CERRADA. Solo el responsable o supervisor las atiende y solo el supervisor verifica cierre o reapertura. Una incidencia alta/critica retiene un lote aprobado; cerrar todas permite liberarlo de nuevo. Las acciones, comprobaciones y evidencias quedan en trazabilidad.

## Integracion con Identity y cupos

Crear lote consume el recurso lotes de Identity. La operacion local se registra antes de consumir y conserva input, huella, actor, estado de consumo, bloqueo temporal y resultado. Repetir la misma clave e input reutiliza el resultado; input/actor distintos devuelven 409. Ante un timeout, reintentar la misma clave permite recuperar la confirmacion idempotente de Identity. No generar una nueva clave para reintentar la misma operacion.

El consumo remoto y la escritura local no son una transaccion distribuida. Si se consume y luego falla la escritura, la operacion persistida permite reintentar con la misma clave; los casos irrecuperables requieren conciliacion. No existe devolucion automatica de cupos. La anulacion posterior del lote tampoco devuelve el cupo consumido.

La consulta de responsables de incidencias usa el API interno de destinatarios de Identity para comprobar empresa, estado y permiso lotes.ver, sin consultar su base.

## IA y worker persistente

`POST /api/v1/evidences/{id}/analyze` devuelve 202 y guarda un trabajo; no hace inferencia dentro de la peticion. El worker usa `POST /api/v1/analyses/file` con X-API-Key y el archivo original, sin enviar IDs de negocio a IA. Verifica request_id, modelo, detecciones y SHA-256 contra el original y guarda el resultado completo y la version del modelo. No usa el antiguo /detect ni convierte la respuesta en un dictamen agricola.

Los trabajos tienen lease, token de bloqueo y hasta tres intentos; se recuperan leases vencidos, incluso tras una caida en el ultimo intento. Los resultados tardios no sustituyen un trabajo reclamado por otro proceso. El consumo analisis_ia usa una referencia estable por evidencia: reintentar esa evidencia no descuenta nuevamente. Una llamada a FastAPI interrumpida puede haber completado una inferencia remota: el servicio IA actual no ofrece idempotencia de la inferencia y no se promete exactamente una ejecucion externa.

Tras agotar intentos se puede reintentar o justificar revision manual. `POST /evidences/{id}/analysis/cancel` permite abandonar un trabajo que no esta ejecutandose, conservando su historial y el archivo. No cancelar ni descartar una foto con analisis en curso.

Ejecutar `php artisan agricultural:work` para procesar IA y outbox; `--once` limita una pasada. Este worker es propio del servicio y no requiere ejecutar queue:work. En produccion supervisarlo y compartir el volumen de evidencia con la API.

## Eventos para Notifications

Se emiten lot.created, lot.cancelled, lot.received, inspection.validated, quality.decision.issued, incident.opened, incident.updated y evidence.analysis_failed. El contrato local es events.schema.json: eventId UUID, type, version=1, companyId, occurredAt y data. Los destinatarios se expresan con recipientPermissions o recipientIds dentro de data; Notifications debe comprobarlos con Identity.

El outbox entrega el cuerpo JSON exacto mediante POST a NOTIFICATIONS_EVENT_URL, con X-Event-Id y X-AgroCloud-Signature = sha256= + HMAC-SHA256 usando AGRICULTURAL_EVENT_SECRET. Reintenta con espera creciente y bloqueos, por lo que el receptor debe deduplicar eventId. Si no hay URL, guarda pendientes sin enviarlos. El Compose raiz conecta este outbox con el receptor firmado de ms-notifications. No activar un destino que responda 2xx sin persistir el evento.

## Preparacion (sin ejecutar en esta entrega)

- Completar .env con PostgreSQL, APP_KEY, URLs y claves de Identity/IA. La clave de Identity debe coincidir con IDENTITY_SERVICE_API_KEY y la de IA con SERVICE_API_KEY de ms-ai. Usar HTTPS fuera de local y APP_DEBUG=false.
- Revisar las migraciones, luego ejecutarlas explicitamente y ejecutar db:seed para catalogos. No se crean usuarios ni datos operativos. Las factories requieren CompanyContext::run y solo producen fixtures de metadatos, no fotografias reales.
- Configurar primero Identity y sus planes/cupos; despues crear un procedimiento desde una cuenta ADMIN y operar con roles RECEPCION, INSPECTOR y SUPERVISOR apropiados.
- Para Docker usar compose.yaml raiz: incluye Dockerfile propio, configuracion y agricultural-worker sin perfil adicional. El volumen agricultural-evidence se comparte entre API y worker. El overlay del servicio se conserva como override opcional. Seguir el aprovisionamiento del README raiz; Compose usa .env raiz. Los servidores PHP incluidos son de desarrollo.
- Para ejecucion manual se necesitan PHP 8.4, Composer 2 y PostgreSQL. No se requieren Laravel/Blade/Vite para servir la API; los archivos frontend heredados no se registran como rutas.

Los endpoints publicos se publican como `/api/v1/agricultural/...` mediante el Gateway existente. Ver openapi.yaml para las rutas y cuerpos de solicitud. El contrato compartido fuera de este servicio queda intacto por el alcance pedido.

Readiness comprueba conexion DB, tablas principales y configuracion basica de Identity; no ejecuta inferencias, entrega notificaciones ni comprueba todos los upstreams. En esta entrega no se ejecutaron tests, compilaciones, contenedores, migraciones, seeders, trabajos ni llamadas a servicios de negocio. La validacion es estatica y el funcionamiento integrado queda pendiente de esa ejecucion.
