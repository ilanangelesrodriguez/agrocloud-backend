# Integración HTTP sin acoplamiento entre sistemas

Este proyecto contiene solo Python. Para integrarlo desde cualquier backend:

1. El backend receptor ya conoce qué operación y fotografía está procesando.
2. Envía la imagen al servicio IA con la clave privada del microservicio.
3. Obtiene un JSON de predicciones, `request_id` y `model_id`.
4. Guarda los resultados junto a su propia evidencia **en su propia base de datos**.
5. Realiza confirmaciones, correcciones y cierre del proceso localmente.

No incluir identificadores del proceso en `POST /api/v1/analyses/file` ni en `/url`.

**Correlación correcta**: el backend mantiene el vínculo (foto → respuesta JSON)
y opcionalmente conserva `request_id` para auditoría técnica; el microservicio
jamás comprueba claves foráneas del backend. El resultado no conoce su procedencia.

**Si se desea una integración de transición** desde un cliente que llama al endpoint
antiguo `/api/v1/detect`, hay que actualizar ese cliente para consumir
`/api/v1/analyses/file` o `/api/v1/analyses/url` y mapear `detections`, `summary`
y `annotated_image_url` en su lado. No se incluye compatibilidad legacy aquí,
para no mantener lógica innecesaria.

**Si se desea dashboard por empresa o lote**, las agregaciones se calculan en
el backend del negocio a partir de sus evidencias y predicciones guardadas.
El endpoint `/api/v1/stats/*` responde únicamente sobre el tráfico técnico
del servicio de IA, no sobre empresas ni operaciones agrícolas.
