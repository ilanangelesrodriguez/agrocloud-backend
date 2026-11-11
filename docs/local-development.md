# 💻 Desarrollo local

**Requisito principal:** Docker Compose v2. El archivo oficial del entorno es [`compose.yaml`](../compose.yaml).

## 1. Configurar el entorno

```bash
cp .env.example .env
```

Completa las variables requeridas: contraseñas, `APP_KEY` de cada Laravel, claves internas y credenciales externas. **No subas `.env` al repositorio.**

## 2. Preparar servicios y bases

```bash
docker compose -f compose.yaml up -d postgres mongo
docker compose -f compose.yaml build

# Identity: claves, esquema y administrador inicial
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan identity:keys
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan migrate
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan db:seed

# Agricultural: esquema y catálogos
docker compose -f compose.yaml run --rm --no-deps ms-agricultural php artisan migrate
docker compose -f compose.yaml run --rm --no-deps ms-agricultural php artisan db:seed

# Notifications: migraciones explícitas
docker compose -f compose.yaml run --rm --no-deps ms-notifications node scripts/migrate.mjs
```

Define `IDENTITY_BOOTSTRAP_EMAIL` y `IDENTITY_BOOTSTRAP_PASSWORD` antes del seed inicial; retíralos después. La migración de AI se ejecuta mediante Alembic durante su arranque, según su configuración.

## 3. Iniciar el sistema

```bash
docker compose -f compose.yaml up -d
docker compose -f compose.yaml ps
```

## 4. Verificar

| Servicio | Puerto local | Salud |
| :-- | --: | :-- |
| Gateway | `4000` | `/health` y `/docs` |
| Identity | `8001` | `/api/v1/health` |
| Agricultural | `8002` | `/api/v1/health` |
| AI | `8003` | `/health` |
| Notifications | `4002` | `/health` |
| Billing | `4001` | `/health` |

Ejemplo: <http://localhost:4000/docs>.

## Procesamiento en segundo plano

Agricultural y Notifications incluyen workers en Compose. El correo de Identity se habilita con el perfil `identity-mail`; Billing permite un worker de eventos con el perfil `events` si desactivas antes su temporizador en la API.

> `health` confirma que el proceso responde. No verifica por sí solo pagos, inferencias, correo ni todos los permisos.

[← Documentación](README.md)
