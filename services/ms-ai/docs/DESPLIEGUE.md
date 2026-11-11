# ☁️ Despliegue · AgroCloud AI

**Python 3.12 · FastAPI · PostgreSQL · Roboflow · Cloudinary**

## Entornos

| Entorno | Base de datos | Configuración |
| :-- | :-- | :-- |
| Desarrollo | SQLite o PostgreSQL | `APP_ENV=development` |
| Pruebas | Base aislada | `APP_ENV=test` |
| Producción | PostgreSQL independiente | `APP_ENV=production`, `AUTO_CREATE_TABLES=false` |

## Configuración

Utiliza [`.env.example`](../.env.example). Las variables esenciales son `DATABASE_URL`, `SERVICE_API_KEY`, `ROBOFLOW_API_KEY`, `CLOUDINARY_URL` y `CLOUDINARY_CLOUD_NAME`.

No subas secretos al repositorio. En Google Cloud, utilízalos desde **Secret Manager**, con permisos para la cuenta de servicio de AI.

## Migraciones

AI usa **Alembic**. Aplica sus migraciones con una conexión autorizada antes de iniciar una versión que dependa de cambios en el esquema:

```bash
# Desde services/ms-ai, con el entorno configurado
pip install -e '.[postgres]'
alembic upgrade head
```

El contenedor **no ejecuta migraciones automáticamente**. `AUTO_CREATE_TABLES=false` impide crear tablas de forma implícita en producción.

## Docker local

```bash
# Desde services/ms-ai
docker build -t agrocloud-ai:local .
docker run --rm --env-file .env -p 8000:8000 agrocloud-ai:local
```

Para todo el monorepo utiliza el [`compose.yaml`](../../../compose.yaml) de la raíz y sigue la [guía local](../../../docs/local-development.md).

## Cloud Run

El servicio existente es `agrocloud-ai` en `us-central1`. Si cambias su código, publica **una imagen nueva**; no recrees la base de datos ni sobrescribas sus secretos.

```bash
# Desde la raíz del monorepo
export PROJECT_ID="project-bd682e97-0f13-4d07-a8a"
export REGION="us-central1"
export REGISTRY="${REGION}-docker.pkg.dev/${PROJECT_ID}/agrocloud"
export TAG="$(git rev-parse --short=12 HEAD)"

gcloud builds submit services/ms-ai \
  --tag="${REGISTRY}/ai:${TAG}" \
  --region="$REGION" --project="$PROJECT_ID"

gcloud run deploy agrocloud-ai \
  --image="${REGISTRY}/ai:${TAG}" \
  --region="$REGION" --project="$PROJECT_ID" \
  --no-allow-unauthenticated
```

Este despliegue reutiliza la configuración del servicio existente. Verifica previamente que la cuenta de servicio, variables y secretos sigan configurados. **Cloud Run privado puede responder `403` si se visita directamente sin autorización IAM.**

## Verificación

```bash
gcloud run services logs read agrocloud-ai --region=us-central1 --limit=30
gcloud run services proxy agrocloud-ai --region=us-central1 --port=8080
```

Con el proxy activo, abre `/health`, `/ready` y `/docs` en la vista previa del puerto 8080. Después ejecuta una **inferencia real de prueba** para validar Roboflow, Cloudinary y los resultados.

> `/ready` no prueba inferencia. Los límites, costos, latencias y métricas de Test deben evaluarse por separado.

[← Índice AI](README.md) · [Cloud Run (guía general)](../../../docs/deployment/cloud-run.md)
