<div align="center">

# 🏗️ Infrastructure

**Construcción de contenedores y aprovisionamiento local de AgroCloud.**

![Docker](https://img.shields.io/badge/Docker-Contenedores-2496ED?logo=docker&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-Inicialización-4169E1?logo=postgresql&logoColor=white)
![Node.js](https://img.shields.io/badge/Node.js-22-339933?logo=nodedotjs&logoColor=white)

[← Proyecto](../README.md) · [Desarrollo local](../docs/local-development.md) · [Cloud Run](../docs/deployment/cloud-run.md)

</div>

---

## ¿Qué contiene?

```text
infrastructure/
├── docker/
│   ├── node.Dockerfile       # Gateway, Billing y Notifications
│   └── laravel.Dockerfile    # Imagen PHP genérica para desarrollo
└── postgres/
    └── init.sh              # Bases y usuarios locales
```

| Componente | Función |
| :-- | :-- |
| **Node Dockerfile** | Construcción por etapas con Node.js 22 y `WORKSPACE` seleccionable. |
| **Laravel Dockerfile** | PHP 8.4, Composer y extensiones de PostgreSQL. Usa `artisan serve` para desarrollo. |
| **PostgreSQL init** | Crea bases y usuarios independientes para Identity, Agricultural, Notifications y AI. |

> **Importante:** `postgres/init.sh` solo se ejecuta al inicializar un volumen **vacío**. No crea ni migra bases ya existentes.

## Inicio rápido

Desde la raíz del repositorio, configura `.env` y ejecuta:

```bash
docker compose -f compose.yaml up -d postgres mongo
docker compose -f compose.yaml build
```

Antes de iniciar todos los servicios, aplica las migraciones y prepara las claves según la [guía local](../docs/local-development.md).

## Construcción de una imagen Node

Ejemplo para Gateway, ejecutado desde la raíz:

```bash
docker build \
  -f infrastructure/docker/node.Dockerfile \
  --build-arg WORKSPACE=gateway \
  -t agrocloud/gateway:local .
```

También se admite `WORKSPACE=services/ms-billing` y `WORKSPACE=services/ms-notifications`.

## Consideraciones de producción

- El **Dockerfile Laravel genérico** utiliza un servidor de desarrollo; no debe tratarse como servidor HTTP endurecido de producción.
- En Cloud Run se utilizan imágenes por servicio, Secret Manager y bases de datos externas (Neon y MongoDB Atlas).
- Los secretos, claves RSA y archivos privados no deben incluirse en las imágenes.
- Las migraciones y las copias de seguridad se gestionan por separado.

---

<sub>AgroCloud · Infraestructura y contenedores</sub>
