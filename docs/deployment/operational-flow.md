# Activación de las mejoras del flujo operativo

Cambios preparados el 20 de noviembre de 2026. No se ejecutaron tests, compilaciones, migraciones, seeders, contenedores ni despliegues. Los comandos de este documento son instrucciones para ejecución manual posterior.

## Orden de activación

1. Respaldar las bases y actualizar las imágenes de todos los servicios y workers desde la misma versión.
2. Si aún no se aplicaron las migraciones de la entrega anterior, aplicarlas en la base propia de cada servicio. Las revisiones de esquema requeridas se detallan en la actualización de IA y maestros al final de esta guía.
3. Registrar los permisos específicos en Identity antes de publicar la interfaz y las rutas que los exigen.
4. Configurar las claves y destinos de eventos; arrancar o actualizar los workers y el scheduler.
5. Publicar el frontend junto con el backend que incorpora `workflow`, filtros de dictamen, selectores y reportes ampliados.

## Permisos de Identity

La actualización aditiva está en `OperationalPermissionsSeeder`. Ejecutar después de disponer de las tablas y roles existentes:

```powershell
docker compose exec ms-identity php artisan db:seed --class=OperationalPermissionsSeeder --force
```

No restablece roles ni elimina permisos personalizados. Asigna por defecto:

| Permiso              | Roles                                               |
| -------------------- | --------------------------------------------------- |
| `incidencias.ver`    | ADMIN, RECEPCION, INSPECTOR, SUPERVISOR, PRODUCCION |
| `incidencias.crear`  | ADMIN, RECEPCION, INSPECTOR, SUPERVISOR             |
| `incidencias.cerrar` | SUPERVISOR                                          |
| `produccion.ver`     | ADMIN, PRODUCCION                                   |

El seguimiento conserva la comprobación de responsable asignado o supervisor. Cerrar, reabrir y devolver una incidencia resuelta a atención exige supervisor con `incidencias.cerrar`. El permiso de cierre por sí solo no sustituye esa comprobación. Los menús y el BFF usan los mismos permisos efectivos. Las cuentas deben renovar su sesión si conservan permisos anteriores en caché.

## Migraciones anteriores pendientes

Solo si no están aplicadas, sobre las bases correctas y con copia de seguridad:

```powershell
docker compose exec ms-identity php artisan migrate --force
docker compose exec ms-agricultural php artisan migrate --force
docker compose exec ms-notifications npm run migrate
```

Identity necesita la tabla `identity_notification_outbox`; Notifications debe registrar `002_portal_destinations.mjs`. Para categorías comerciales y motivos que todavía falten:

```powershell
docker compose exec ms-agricultural php artisan db:seed --class=CatalogSeeder --force
```

No utilizar `migrate:fresh` ni reiniciar las bases. No renombrar maestros usados mediante SQL: la aplicación bloquea ese cambio para conservar los textos históricos de lotes, muestras, procedimientos y dictámenes. Hasta adoptar referencias estables por ID, deshabilitar el maestro y crear uno nuevo es la alternativa compatible.

## Eventos en Docker Compose

Configurar en el `.env` raíz `IDENTITY_EVENT_SECRET` y `BILLING_EVENT_SECRET` con claves distintas de al menos 32 caracteres. Cada una debe coincidir entre emisor y receptor; no almacenar valores reales en Git.

Compose ya transmite:

- Identity → `http://ms-notifications:3000/api/v1/internal/events/identity`.
- Billing → `http://ms-notifications:3000/api/v1/internal/events/billing`.
- El servicio `identity-scheduler` reutiliza el entorno y volumen de claves de Identity y ejecuta `schedule:work`.
- `identity-mail-worker` sigue atendiendo la cola de correo; no sustituye al scheduler.

Actualizar los contenedores siguiendo el procedimiento habitual después de preparar esquema y permisos. No arrancar automáticamente servicios con migraciones pendientes. Las entregas de Billing a Identity y Notifications conservan registros y reintentos independientes.

## Servicios privados desplegados

Configurar en cada emisor `NOTIFICATIONS_EVENT_URL` con el endpoint HTTPS correspondiente a su origen y `NOTIFICATIONS_CLOUD_RUN_AUDIENCE` con la audiencia canónica del receptor. Conservar HMAC y conceder `roles/run.invoker` a las cuentas de servicio emisoras sobre Notifications. Billing mantiene su configuración independiente de Identity.

El outbox de Identity requiere un proceso con ejecución programada o permanente. La cola de correo por sí sola no lo consume. Para un proceso programado, su entrada es `php artisan identity:dispatch-notifications --limit=100`; para scheduler permanente, `php artisan schedule:work`. Configurar su ejecución efectiva en el entorno, sin depender de una petición HTTP al portal.

## Semántica del flujo y los reportes

- `GET /lots/{lot}` devuelve `workflow`: etapa actual, siguiente acción con permiso y `canExecute` según las restricciones de rol vigentes, bloqueos, muestreo e inspección vigentes, dictamen y etapas completadas.
- Calidad consulta `decision_status=PENDING|OBSERVED|COMPLETED`. Pendientes y observadas excluyen inspecciones sustituidas por otras más recientes en el mismo muestreo. Observadas muestra correcciones documentales o administrativas; una reinspección se atiende desde la nueva inspección.
- `GET /production/lots` requiere `produccion.ver` y solo devuelve lotes clasificados sin incidencias altas o críticas sin cerrar.
- Los reportes filtran la cohorte por fecha UTC de registro del lote, producto, variedad y productor. Cada lote aporta su último dictamen; no se suman decisiones históricas como resultados nuevos.
- Las tasas usan los lotes con dictamen como denominador; las duraciones están en horas y son nulas si faltan datos. Los defectos frecuentes mostrados son hallazgos manuales.
- IA confirmada y descartada corresponde a validaciones humanas. `requested_evidences` cuenta evidencias con solicitud de IA, no solicitudes HTTP, reintentos ni cuotas facturadas. El consumo contractual continúa en Suscripción.
- El CSV exporta el resultado consultado y protege los textos que podrían interpretarse como fórmulas en una hoja de cálculo.

## Alcance posterior

Se prepararon referencias estables `producto_id`, `variedad_id` y `metodo_id` con una migración aditiva y snapshots históricos. Su activación y el tratamiento de registros ambiguos se detallan en la actualización al final de esta guía.

La administración global de maestros por SUPERADMIN requiere un flujo de plataforma separado del contexto empresarial; no se habilita saltando el aislamiento de empresa. No se añadieron permisos implícitos para atravesar ese límite.

No se verificó el funcionamiento en ejecución ni se modificaron IAM, bases, secretos reales o infraestructura desplegada.

## Experiencia por roles, maestros e IA — actualización

Los siguientes cambios están preparados en archivos. No se ejecutaron tests, compilaciones, migraciones, seeders, inferencias, benchmarks ni despliegues durante esta actualización.

### Preparar esquema y permisos antes de arrancar la nueva versión

- Agricultural: la migración `2026_11_20_000002_add_stable_catalog_references.php` agrega `producto_id`, `variedad_id` y `metodo_id`, preservando los nombres históricos. El backfill solo asigna coincidencias visibles y no ambiguas. Los valores ambiguos permanecen sin referencia y requieren una correspondencia revisada; no elegir arbitrariamente un ID ni renombrar snapshots históricos.
- IA: la revisión Alembic `0002_monitoring_indexes.py` agrega el umbral consultable e índices por fecha, modelo y estado. Los umbrales históricos se recuperan de `result_json`; los que no existen siguen nulos. Revisar el impacto de crear índices en tablas grandes y aplicar durante una ventana apropiada antes de arrancar procesos con el ORM actualizado.
- Identity: `OperationalPermissionsSeeder` registra `ia.reportes.ver` para ADMIN, SUPERVISOR e INSPECTOR, y `ia.modelo.ver` para SUPERADMIN, ADMIN, SUPERVISOR e INSPECTOR; `ia.monitoreo.ver` e `ia.historial.ver` se asignan únicamente a SUPERADMIN. El BFF exige además el rol SUPERADMIN para las consultas globales. No asignar los permisos globales a cuentas empresariales como alternativa a reportes por empresa.

Comandos para ejecución manual posterior, no ejecutados aquí:

```powershell
docker compose exec ms-agricultural php artisan migrate --force
docker compose exec ms-identity php artisan db:seed --class=OperationalPermissionsSeeder --force
# Dentro del entorno o imagen de ms-ai, con su DATABASE_URL propia:
alembic upgrade head
```

Si un paso ya fue aplicado por otra entrega, comprobar el registro de versiones antes de repetir trabajos de datos. El nuevo código del ORM de IA requiere el esquema actualizado; no desplegarlo primero sobre una base sin `threshold`.

### Integración privada de IA

En el entorno del servidor Next.js configurar `AI_SERVICE_API_KEY` con la misma clave `SERVICE_API_KEY` de ms-ai. Nunca usar `NEXT_PUBLIC_` ni guardar credenciales reales en Git. Las consultas pasan por el Gateway ya configurado en `AGROCLOUD_GATEWAY_URL`; el BFF valida sesión, rol y permiso antes de agregar `X-API-Key`. En servicios privados de Cloud Run, conservar la autenticación IAM que ya agrega el Gateway y sus permisos de invocación hacia ms-ai.

El Gateway publica una lista cerrada de consultas GET. No publica análisis ni redirecciones de imágenes globales a través de esta nueva interfaz. El historial del portal muestra metadatos y resultados técnicos autorizados, sin fotografías ni URLs de imágenes. Agricultural sigue enviando sus análisis por la integración privada existente.

### Ámbitos y significado de los indicadores

- `/admin/ai`, `/admin/ai/monitoring`: volumen, éxito, procesamiento, errores y latencias del servicio completo. Nunca representan una empresa concreta.
- `/admin/ai/model`, `/admin/ai/evaluation`: configuración efectiva, snapshot científico V2, dataset, clases y matriz de confusión. Una versión sin snapshot verificado devuelve 404. No se inventan evaluaciones para V1 o V3.
- La asociación V2 requiere coincidencia del modelo de inferencia, modelo entrenado, workspace y modo. Una configuración diferente no hereda automáticamente familia, dataset o métricas V2. Cada inferencia nueva guarda su umbral efectivo.
- El umbral operativo puede diferir del 0.40 de la matriz histórica. mAP no es exactitud; el F1 reportado se conserva sin recalcularlo. Una clase sin anotaciones Test se presenta como no evaluable.
- `/reports/ai` requiere `ia.reportes.ver`: exclusivamente evidencias de la empresa autenticada. Filtros por fecha UTC de registro de evidencia, modelo, producto, variedad y productor. Sus endpoints pertenecen a Agricultural: `/reports/ai/overview`, `/classes`, `/daily`, `/models` y `/validation` bajo el mismo prefijo.
- Las validaciones se unen por empresa, evidencia e ID actual de detección. No se cuentan validaciones sustituidas al repetir un análisis ni fotografías descartadas. La tasa de confirmación humana no equivale a precisión científica, recall o mAP. El consumo contractual permanece en Suscripción; contar evidencias no equivale a contar cuotas facturadas.
- `/ready` verifica configuración, esquema y base de datos. El dashboard lo indica expresamente: no consume una inferencia ni demuestra disponibilidad real del proveedor.

### Continuidad y rendimiento

El dashboard ya no usa una página de lotes como fuente de indicadores. Incluye muestreos sin inspección y observaciones documentales por resolver. El resumen del lote diferencia captura, IA opcional y revisión humana, considera asignación e independencia del supervisor, y deshabilita acciones con restricciones de negocio.

Los catálogos operativos y administrativos usan consultas y claves de caché diferentes. Los supervisores no necesitan permiso administrativo para cargar categorías y motivos. Los formularios envían IDs estables y conservan nombres como snapshots. Un maestro con procedimientos activos dependientes no se puede deshabilitar; sus escrituras se serializan con las validaciones de procedimientos. La lista de preparación agrícola comprueba maestros y procedimientos utilizables, sin afirmar que los datos legales de empresa o los usuarios ya estén configurados.

Las mutaciones invalidan las vistas agrícolas derivadas, incluidas Calidad, Dashboard, Reportes, preparación e IA empresarial. Notificaciones y Suscripción se actualizan adicionalmente solo cuando la operación afecta esos ámbitos. El expediente se actualiza mientras existen análisis pendientes. El selector de productores consulta el catálogo completo paginado, incluso para productores sin dictamen.

Las estadísticas técnicas usan agregaciones SQL; los percentiles no cargan todo el historial en memoria. El historial está paginado, las consultas científicas se reutilizan en caché y los filtros de modelo esperan una breve pausa al escribir. La revisión de fotografías usa relaciones precargadas para evitar consultas por fotografía durante la validación colectiva. Estas son mejoras de implementación: no se midieron latencias ni concurrencia en producción.

Se añadieron los eventos `sampling.created`, `lot.retained` y `lot.released`. El primero es de bandeja interna y no genera correo; los avisos de retención y liberación obedecen las preferencias existentes. Actualizar el receptor Notifications antes de habilitar los emisores nuevos para que reconozca estos contratos.
