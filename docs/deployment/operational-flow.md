# Activación de las mejoras del flujo operativo

Cambios preparados el 20 de noviembre de 2026. No se ejecutaron tests, compilaciones, migraciones, seeders, contenedores ni despliegues. Los comandos de este documento son instrucciones para ejecución manual posterior.

## Orden de activación

1. Respaldar las bases y actualizar las imágenes de todos los servicios y workers desde la misma versión.
2. Si aún no se aplicaron las migraciones de la entrega anterior, aplicarlas en la base propia de cada servicio. Esta entrega no agrega migraciones nuevas.
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

La transición completa a `producto_id`, `variedad_id` y `metodo_id` necesita diseño de compatibilidad y backfill de datos existentes. Se aplica ahora el bloqueo de renombrado recomendado como medida intermedia.

La administración global de maestros por SUPERADMIN requiere un flujo de plataforma separado del contexto empresarial; no se habilita saltando el aislamiento de empresa. No se añadieron permisos implícitos para atravesar ese límite.

No se verificó el funcionamiento en ejecución ni se modificaron IAM, bases, secretos reales o infraestructura desplegada.
