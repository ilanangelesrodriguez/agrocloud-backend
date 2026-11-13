# ☁️ Despliegue en Cloud Run

AgroCloud se despliega en **Google Cloud Run** con imágenes alojadas en **Artifact Registry** y credenciales de **Secret Manager**.

## Servicios

| Servicio | Cloud Run | Acceso previsto |
| :-- | :-- | :-- |
| Gateway | `agrocloud-gateway` | Entrada pública controlada |
| Identity | `agrocloud-identity` | Privado |
| Agricultural | `agrocloud-agricultural` | Privado |
| Notifications | `agrocloud-notifications` | Privado |
| AI | `agrocloud-ai` | Privado |
| Billing | `agrocloud-billing` | Privado |

**Proyecto de pruebas:** `project-bd682e97-0f13-4d07-a8a` · **Región:** `us-central1`.

## Publicar cambios

1. Sincroniza `main` y verifica los cambios locales.
2. Construye una **imagen nueva solo del servicio modificado**, con etiqueta basada en el commit.
3. Publícala en Artifact Registry usando Cloud Build.
4. Actualiza el servicio existente con `gcloud run deploy` y su nueva imagen.
5. Prueba `/health`, `/ready` y una operación real autorizada.

Ejemplo para actualizar **Gateway**:

```bash
export PROJECT_ID="project-bd682e97-0f13-4d07-a8a"
export REGION="us-central1"
export REGISTRY="$REGION-docker.pkg.dev/$PROJECT_ID/agrocloud"
export TAG="$(git rev-parse --short=12 HEAD)"

cat > /tmp/cloudbuild-gateway.yaml <<EOF_BUILD
steps:
  - name: gcr.io/cloud-builders/docker
    args: [build, --platform, linux/amd64, -f, infrastructure/docker/node.Dockerfile, --build-arg, WORKSPACE=gateway, -t, ${REGISTRY}/gateway:${TAG}, .]
images:
  - ${REGISTRY}/gateway:${TAG}
EOF_BUILD

gcloud builds submit . --config=/tmp/cloudbuild-gateway.yaml --region="$REGION" --project="$PROJECT_ID"
gcloud run deploy agrocloud-gateway --image="$REGISTRY/gateway:$TAG" --region="$REGION" --project="$PROJECT_ID"
```

> El ejemplo actualiza **una imagen**, sin volver a crear las bases de datos ni reemplazar los secretos ya configurados. Para otros servicios, utiliza su Dockerfile y los parámetros correspondientes. Valida los cambios de seguridad antes de promover nuevas revisiones.

## Verificación

```bash
gcloud run services list --region="$REGION" --project="$PROJECT_ID"
gcloud run services logs read agrocloud-gateway --region="$REGION" --limit=30
```

Para probar un servicio privado usa autenticación IAM o `gcloud run services proxy`; el `403` al abrir su URL directamente en el navegador es esperado.

## Alta empresarial en Identity

- Define `IDENTITY_PRIVACY_VERSION` con la misma versión del aviso publicado que envía el frontend. Define `IDENTITY_FREE_PLAN_DAYS`, `IDENTITY_FREE_PLAN_USERS`, `IDENTITY_FREE_PLAN_LOTS` e `IDENTITY_FREE_PLAN_AI` con la duración y los cupos aprobados antes de habilitar aprobaciones.
- Respalda PostgreSQL y aplica las migraciones de `ms-identity` antes de habilitar la nueva revisión. La migración de reenvíos conserva los intentos rechazados. Si existen varias solicitudes para el mismo RUC, la reversión se detiene antes de modificar los índices. Prepara un plan que preserve el historial antes de intentar la reversión.
- Configura `IDENTITY_GATEWAY_CLIENT_IP_KEY` como un secreto aleatorio compartido exclusivamente por Gateway e Identity. En el Gateway, configura `GATEWAY_TRUSTED_PROXY_CIDRS` con las redes de los proxies de entrada que realmente controlan la cadena `X-Forwarded-For`. Evita rangos abiertos o confiar en un número fijo de saltos. Si el Gateway recibe conexiones directas, deja la lista vacía. Si el ingreso no ofrece una cadena verificable, aplica el límite por visitante en esa capa.
- En `IDENTITY_BILLING_OFFERS` puedes indicar `description` y las fechas `valid_from` (inclusive) y `valid_until` (exclusiva), con formato `YYYY-MM-DD` en UTC. Las fechas son opcionales; cuando se configuran, el catálogo y las compras nuevas respetan esa vigencia.


## Catálogo comercial de Identity

- Aplica las migraciones de Identity antes de publicar la revisión que consulta el catálogo persistente.
- Los superadministradores pueden crear y revisar ofertas mediante `/api/v1/identity/billing-offers`. Envía la versión actual al editar. Publicar, editar o archivar registra una nueva versión con el actor y la fecha.
- Las claves de `IDENTITY_BILLING_OFFERS` siguen disponibles y quedan reservadas mientras estén configuradas. Para trasladar una oferta, coordina el retiro de esa clave de la configuración y la publicación de la versión administrada. Una orden pendiente conserva su referencia, su precio y sus cupos originales.
- `GET /api/v1/identity/subscription/offers` devuelve las ofertas aplicables a la empresa o la orden pendiente que debe retomar.
- Configura al menos cuatro cuentas en el plan gratuito si quieres que una empresa pueda operar con los cuatro roles del flujo de calidad. Ajusta los demás cupos según las condiciones aprobadas.
## Seguridad y pendientes

- Mantén privados los cinco microservicios; solo publica las rutas necesarias mediante Gateway.
- Usa **IAM + ID token de Google** para llamadas entre servicios privados, conservando el JWT de usuario por separado.
- Restringe rutas privilegiadas de Billing y AI; ocultarlas en Swagger no es suficiente.
- Configura CORS con el origen real del frontend y gestiona las claves en Secret Manager.
- Valida workers, eventos HMAC, webhooks de Culqi, análisis de imágenes y alertas antes de producción.

[← Documentación](../README.md)
