# Arquitectura: servicio EXCLUSIVO de inteligencia artificial

## Límite de dominio

**El proyecto consume imágenes y produce predicciones técnicas.** No conoce, consulta,
valida ni almacena datos de empresa, lote, recepción, muestreo, inspección, fruto,
usuarios o dictámenes. El cliente llamante es dueño de sus datos de negocio.

No contiene PHP, controladores Laravel, claves de tabla ajena ni relaciones entre bases.

## Capas

```text
                  Cliente backend HTTP / Swagger
                               |
                    Autenticación X-API-Key
                               |
                   FastAPI (api/routes/)
                               |
                 Application (analyze.py)
                      /       |        \
                     v        v         v
          Domain/Vision    Roboflow    ImageStorage
          cajas, calidad   adaptador    local/Cloudinary
                     \       |         /
                      +----Resultado---+
                               |
                 SQLAlchemy: historial TÉCNICO
                               |
                  SQLite local / PostgreSQL IA
```

- `api`: endpoints HTTP, contratos OpenAPI y Swagger.
- `core`: configuración, seguridad y excepciones.
- `domain`: estructuras y cálculos de detección y evaluación.
- `application`: casos de uso que no dependen de un framework de negocio.
- `infrastructure/inference`: proveedor Roboflow.
- `infrastructure/storage`: imágenes anotadas y descarga segura.
- `infrastructure/persistence`: registro de inferencias y predicciones, sin entidades agrícolas.
- `resources`: resultados congelados de evaluación de RF-DETR Small V2.

## Persistencia opcional del dominio técnico

Estas dos tablas guardan ÚNICAMENTE información generada por el servicio:

- `ai_inference_runs`: UUID `request_id`, huella SHA-256, versión del modelo,
  estado, duración, tipo de error y JSON técnico devuelto.
- `ai_detected_objects`: bounding boxes y puntuaciones detectadas.

La BD aquí **no es** ni necesita ser la BD PostgreSQL de AgroCloud Laravel.
En producción se recomienda una instancia/esquema aislado con credenciales propias.
Para una instalación muy simple puede deshabilitarse la persistencia mediante una
futura implementación de repositorio sin estado; esta versión la usa para estadísticas.

## Relación con el sistema principal

1. El sistema consumidor obtiene su propia imagen según sus reglas de negocio.
2. Envía solo el archivo binario o una URL Cloudinary autorizada al microservicio.
3. RF-DETR devuelve `request_id`, versión, confianza, cajas, resumen, imagen anotada y tiempos.
4. El consumidor almacena el `request_id` devuelto **en su propia tabla** junto con
   los datos de negocio que solamente él conoce.
5. La validación y la decisión final pertenecen al consumidor, no al microservicio.

El `request_id` es propio del servicio IA y NO es una FK hacia otro sistema.

## Consideraciones operativas

- Los resultados experimentales publicados en Roboflow no son métricas de operación.
- Una detección no constituye diagnóstico fitosanitario ni autoriza rechazar fruta.
- El umbral es configurable: no asumir que 0.20 sea óptimo para todas las clases.
- Se requiere HTTPS y API key solo entre servidores; nunca exponerla en frontend.
- La lista de inferencias y los datos agregados son GLOBALes al servicio;
  restringir su acceso a operadores internos confiables, no usuarios finales multiempresa.
- Para miles de imágenes o múltiples réplicas, considerar cola de tareas y límites de uso globales.
