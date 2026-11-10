# Ejecución y despliegue Cloud

## Entornos

| Modo | Base de datos | Imagen anotada | Ajustes |
|---|---|---|---|
| Local | SQLite | local | `APP_ENV=development`, `AUTO_CREATE_TABLES=true` |
| Testing | SQLite temporal | local | `APP_ENV=test`, proveedor simulado |
| Cloud | PostgreSQL independiente para registros técnicos IA | Cloudinary | `APP_ENV=production`, `AUTO_CREATE_TABLES=false` |

## Preparar credenciales sin incluirlas en el repositorio

1. Crear un `.env` basado en `.env.example`.
2. `SERVICE_API_KEY`: generar una clave privada aleatoria larga y compartirla **solo** con el backend que consume la API.
3. `ROBOFLOW_API_KEY`: clave del workspace con permiso de inferencia al modelo `agrocloud-plagas-de-palta/2`.
4. `DATABASE_URL`: cadena PostgreSQL **exclusiva del servicio IA**, no la BD de AgroCloud; `postgresql+psycopg://...` con TLS según proveedor.
5. `CLOUDINARY_URL`, `CLOUDINARY_CLOUD_NAME`: almacenamiento de fotos anotadas.
6. `ANNOTATED_STORAGE=cloudinary` si el consumidor necesita URL de imagen anotada.
7. `APP_ENV=production`, `AUTO_CREATE_TABLES=false`.

Nunca colocar credenciales en Swagger, pantallazos, archivos README, Dockerfile, Git o URLs visibles al navegador.

## Migraciones

Con variables de producción cargadas en el entorno y acceso a PostgreSQL:

```bash
pip install -e '.[postgres]'
alembic upgrade head
```

**Nota:** el contenedor ejecuta el servidor, no ejecuta migraciones automáticamente. Aplicarlas como paso de despliegue independiente antes de iniciar. `AUTO_CREATE_TABLES=false` evita modificaciones implícitas de esquema en producción.

## Docker

```bash
docker build -t agrocloud-ai-rfdetr:2 .
docker run --rm --env-file .env -p 8000:8000 agrocloud-ai-rfdetr:2
```

Para desarrollo local:

```bash
docker compose up --build
```

Swagger: `https://TU-DOMINIO/docs`. Salud: `/health`. Ready: `/ready`.

## Consideraciones de rendimiento

- RF-DETR se ejecuta en Roboflow hospedado; la latencia de red no equivale a los 9.5 ms publicados en la evaluación.
- Con `--workers 1` el semáforo limita concurrencia del proceso. Si despliegas más réplicas, controlar límite global a nivel de proveedor o gateway.
- El almacenamiento en SQLite y los percentiles en memoria sirven para pruebas, no para grandes escalas.
- Asegurar timeout HTTP entre el backend y el microservicio mayor al de inferencia, o implementar colas antes de producción de alto volumen.
- Para procesamiento sostenido, registrar costos por llamada, límites, alertas y políticas de retención.
- Habilitar CORS sólo si se demuestra necesario; el diseño correcto usa backend-a-backend.
- Activar HTTPS mediante proxy/ingress, proteger `/docs` y rotar claves por política.

## Verificación después de desplegar

1. `GET /health`: proceso responde.
2. `GET /ready`: BD y configuración presente; **no comprueba Roboflow real**.
3. Abrir Swagger, autorizar `X-API-Key`, verificar `/api/v1/model`.
4. Con imagen de prueba autorizada realizar `POST /api/v1/analyses/file` y comprobar que regrese el modelo V2.
5. Consultar `/api/v1/inferences/{request_id}` y `/api/v1/stats/overview`.
6. Comprobar imagen anotada Cloudinary y latencia extremo a extremo; las validaciones humanas ocurren fuera del servicio.

## Advertencias

- Ninguna API puede garantizar que 78.44% de mAP@50 del Test se repita en campo sin un dataset independiente.
- CHINCHE carece de instancias en el Test consultado: su rendimiento real no está demostrado.
- El `evaluation_v2.json` es una referencia verificable congelada; actualizarlo sólo tras evaluación formal de una nueva versión, con evidencia y fecha.
