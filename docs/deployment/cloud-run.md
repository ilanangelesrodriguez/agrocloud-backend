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

## Seguridad y pendientes

- Mantén privados los cinco microservicios; solo publica las rutas necesarias mediante Gateway.
- Usa **IAM + ID token de Google** para llamadas entre servicios privados, conservando el JWT de usuario por separado.
- Restringe rutas privilegiadas de Billing y AI; ocultarlas en Swagger no es suficiente.
- Configura CORS con el origen real del frontend y gestiona las claves en Secret Manager.
- Valida workers, eventos HMAC, webhooks de Culqi, análisis de imágenes y alertas antes de producción.

[← Documentación](../README.md)
