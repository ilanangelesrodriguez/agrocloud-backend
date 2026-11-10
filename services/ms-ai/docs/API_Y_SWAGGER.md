# Endpoints y Swagger

- Swagger UI: `http://localhost:8000/docs`
- ReDoc: `http://localhost:8000/redoc`
- OpenAPI: `http://localhost:8000/openapi.json`
- Estado: `GET /health` (abierto), `GET /ready` (abierto, no prueba inferencia real).
- Autenticación: `X-API-Key` en `/api/v1/*`, habilitar con **Authorize** en Swagger.

| Método | Endpoint | Responsabilidad |
|---|---|---|
| POST | `/api/v1/analyses/file` | Analizar una imagen subida |
| POST | `/api/v1/analyses/url` | Analizar una URL de Cloudinary de confianza |
| POST | `/api/v1/analyses/batch` | Analizar hasta `MAX_BATCH_SIZE` imágenes en orden |
| GET | `/api/v1/model` | Modelo activo y configuración no sensible |
| GET | `/api/v1/model/evaluation` | Evaluación científica congelada |
| GET | `/api/v1/model/classes` | Métricas de Test por clase |
| GET | `/api/v1/model/confusion-matrix` | Matriz de confusión de Test |
| GET | `/api/v1/inferences` | Historial técnico paginado |
| GET | `/api/v1/inferences/{request_id}` | Resultado por UUID |
| GET | `/api/v1/inferences/{request_id}/image` | JPG anotado cuando el storage es local |
| GET | `/api/v1/stats/overview` | Tasas y latencias del servicio |
| GET | `/api/v1/stats/classes` | Frecuencia de detecciones |
| GET | `/api/v1/stats/errors` | Errores agregados |
| GET | `/api/v1/stats/daily` | Actividad diaria |

## Petición: solo la imagen

```bash
curl -X POST 'http://localhost:8000/api/v1/analyses/file' \
  -H 'X-API-Key: TU_CLAVE_PRIVADA' \
  -F 'file=@palta.jpg'
```

Alternativa usando URL Cloudinary autorizada:

```bash
curl -X POST 'http://localhost:8000/api/v1/analyses/url' \
  -H 'X-API-Key: TU_CLAVE_PRIVADA' \
  -H 'Content-Type: application/json' \
  -d '{"image_url":"https://res.cloudinary.com/MI_CLOUD/image/upload/v1/palta.jpg"}'
```

> La URL ha de pertenecer al `CLOUDINARY_CLOUD_NAME` configurado; no se admiten URLs arbitrarias ni redirecciones.

## Ejemplo de respuesta **ilustrativa**, no una inferencia real

```json
{
  "request_id": "ee464cc7-e183-41d8-a27c-dbc633cb6a76",
  "status": "completed",
  "model_id": "agrocloud-plagas-de-palta/2",
  "trained_model_id": "ilan-angeles/agrocloud-plagas-de-palta-2-rfdetr-small-t1",
  "detections": [{
    "id": "det-0", "class_name": "GRILLOS", "confidence": 0.91,
    "box": {"x1": 10, "y1": 20, "x2": 80, "y2": 100},
    "normalized_box": [0.05, 0.1, 0.4, 0.5],
    "review_priority": "high"
  }],
  "summary": {
    "total_detections": 1,
    "by_class": {"GRILLOS": 1},
    "mean_confidence": 0.91,
    "max_confidence": 0.91,
    "conclusion": "Objetos candidatos detectados; validar manualmente"
  }
}
```

Se omiten campos del ejemplo por brevedad; Swagger muestra el esquema completo
(`quality`, `threshold`, `timings`, `created_at`, `image_sha256`, `annotated_image_url`, etc.).

**NO** existen endpoints de validación humana o de empresa/lote/inspección.
El cliente que llama se ocupa de asociar el JSON devuelto a sus propios registros.

## Advertencias de seguridad

El `request_id` sirve para identificar una ejecución técnica; no otorga permisos.
El historial y las estadísticas de esta API son globales: usar exclusivamente por
servicios backend confiables. No enviar la API key al navegador.
