# 🧭 API y Swagger · AgroCloud AI

**FastAPI · OpenAPI · RF-DETR Small**

La API recibe imágenes y devuelve detecciones técnicas. Sus operaciones de análisis requieren **`X-API-Key`**, reservada para otros servicios backend.

## Documentación disponible

| Ruta directa de AI | Uso |
| :-- | :-- |
| `/docs` | Swagger UI |
| `/redoc` | ReDoc |
| `/openapi.json` | Contrato OpenAPI |
| `/health` | Estado del proceso |
| `/ready` | Configuración y conexión con la base de datos |

> En **Cloud Run**, AI permanece privado. Accede a sus rutas mediante IAM o `gcloud run services proxy`; no publiques `SERVICE_API_KEY` en el navegador. Swagger Gateway documenta el acceso de los clientes a través de Agricultural.

## Operaciones principales

| Método | Ruta directa | Función |
| :-- | :-- | :-- |
| `POST` | `/api/v1/analyses/file` | Analizar una fotografía |
| `POST` | `/api/v1/analyses/url` | Analizar una URL autorizada de Cloudinary |
| `POST` | `/api/v1/analyses/batch` | Analizar de 1 a `MAX_BATCH_SIZE` imágenes |
| `GET` | `/api/v1/model` | Modelo y configuración no sensible |
| `GET` | `/api/v1/model/evaluation` | Evaluación experimental documentada |
| `GET` | `/api/v1/model/classes` | Métricas por clase |
| `GET` | `/api/v1/model/confusion-matrix` | Matriz de confusión de Test |
| `GET` | `/api/v1/inferences` | Historial técnico |
| `GET` | `/api/v1/inferences/{request_id}` | Resultado de una inferencia |
| `GET` | `/api/v1/inferences/{request_id}/image` | Redirección `307` a la imagen anotada en Cloudinary |
| `GET` | `/api/v1/stats/overview` | Volumen y latencias |
| `GET` | `/api/v1/stats/classes` | Frecuencia de detecciones |
| `GET` | `/api/v1/stats/errors` | Errores agregados |
| `GET` | `/api/v1/stats/daily` | Actividad por día |

Las rutas `/api/v1/*` exigen `X-API-Key`; `/health` y `/ready` no exigen la clave de aplicación, pero Cloud Run sí puede exigir autenticación IAM.

## Ejemplo local

```bash
curl -X POST http://127.0.0.1:8000/api/v1/analyses/file \
  -H 'X-API-Key: TU_CLAVE_DE_SERVICIO' \
  -F 'file=@palta.jpg'
```

**Formatos:** JPG, PNG y WebP. El límite predeterminado del archivo es **3 MiB**; las cantidades máximas se configuran en `.env`.

La respuesta incluye `request_id`, `detections`, `summary`, `quality`, `timings` y, cuando esté disponible, `annotated_image_url`.

**Importante:** `/ready` no ejecuta una inferencia con Roboflow. Las estadísticas son **técnicas del servicio**, no métricas de calidad por empresa ni diagnósticos agrícolas.

[← Índice AI](README.md) · [Integración HTTP](INTEGRACION_HTTP.md)
