<div align="center">

# 🔐 AgroCloud Identity

**Autenticación, empresas, usuarios y acceso a los servicios AgroCloud.**

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/Base_de_datos-PostgreSQL-4169E1?style=flat-square&logo=postgresql&logoColor=white)

[**Servicios**](../README.md) · [**Arquitectura**](../../docs/architecture/overview.md) · [**Estándar API**](../../docs/architecture/api-standard.md)

</div>

---

## 🎯 Responsabilidades

- Inicio de sesión, JWT **RS256**, renovación y revocación de sesiones.
- Gestión de empresas, usuarios, roles, permisos y solicitudes de acceso.
- Planes, suscripciones y control de cupos.
- Validación interna de identidad y recepción segura de eventos de pago.

## 📡 Endpoints principales

Base directa: `/api/v1` · A través del Gateway: `/api/v1/identity`

| Método | Ruta directa | Descripción |
| --- | --- | --- |
| `POST` | `/auth/login` | Iniciar sesión |
| `POST` | `/auth/refresh` | Renovar tokens |
| `GET` | `/auth/me` | Consultar perfil autenticado |
| `POST` | `/auth/logout` | Revocar sesión |
| `GET` | `/companies` | Consultar empresas autorizadas |
| `GET` | `/users` | Consultar usuarios |
| `GET` | `/subscription` | Consultar suscripción |
| `POST` | `/subscription/orders` | Iniciar una compra |

**Estado:** `/api/v1/health` · `/api/v1/ready` · **Claves públicas:** `/api/v1/auth/keys`.

Consulta las demás rutas en [OpenAPI](openapi.yaml) o en el Swagger del Gateway (`/docs`).

## 🔗 Integraciones

| Sistema | Para qué se utiliza |
| --- | --- |
| Agricultural / Notifications | Introspección de usuarios y validación de empresa |
| Billing | Creación de órdenes y recepción de pagos firmados |
| PostgreSQL | Cuentas, sesiones, suscripciones y auditoría |

> El `SUPERADMIN` global no representa automáticamente a una empresa agrícola. Los módulos operativos requieren identidad y permisos de una empresa activa.

## 🛠️ Desarrollo

Desde la raíz y **tras configurar `.env`**:

```bash
# Aprovisionamiento inicial (no repetir claves/seed sin revisar datos)
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan identity:keys
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan migrate
docker compose -f compose.yaml run --rm --no-deps ms-identity php artisan db:seed

# Servicio
docker compose -f compose.yaml up -d ms-identity
```

Requiere la base disponible, secretos configurados y `IDENTITY_BOOTSTRAP_EMAIL` / `IDENTITY_BOOTSTRAP_PASSWORD` para el administrador inicial.

## 🔒 Seguridad y operación

- El usuario envía `Authorization: Bearer <JWT>`; las llamadas internas usan `X-Service-Key` y autenticación de infraestructura.
- Conserva las claves RSA fuera de Git y de las imágenes; respalda el almacenamiento persistente.
- Los eventos `payment.succeeded` requieren firma HMAC e idempotencia.
- En producción se recomienda un servidor PHP adecuado y un worker para las tareas de correo.

**Documentación:** [Swagger del Gateway](../../gateway/README.md) · [Inicio local](../../docs/local-development.md) · [Cloud Run](../../docs/deployment/cloud-run.md)

## Alta empresarial desde la landing

Desde el Gateway, usar `POST /api/v1/identity/public/ruc/lookup` con `{ "ruc": "..." }` para consultar los datos tributarios. Si el resultado es `pendiente`, el formulario puede continuar con datos declarados. `GET /api/v1/identity/public/offers` devuelve solo ofertas PLAN con `"public": true` en IDENTITY_BILLING_OFFERS.

El formulario debe enviar `privacy_accepted: true` y `privacy_version` igual a IDENTITY_PRIVACY_VERSION. Configurar esa variable con la versión del aviso que el formulario realmente muestra. Identity registra la fecha y la finalidad, y devuelve `reference` como comprobante. Las solicitudes previas mantienen sus columnas de consentimiento vacías.

Configurar IDENTITY_FREE_PLAN_DAYS, IDENTITY_FREE_PLAN_USERS, IDENTITY_FREE_PLAN_LOTS e IDENTITY_FREE_PLAN_AI con valores aprobados. El alta crea el plan GRATUITO y los cupos en la misma transacción. Si no se verifica un RUC activo y habido, la aprobación de superadministrador requiere `revision_manual: true` y un motivo que se registra en auditoría.

Apuntar IDENTITY_FRONTEND_URL al portal que sirva /activate. Configurar SMTP y el worker de Identity para entregar las invitaciones. La página de activación envía user_id, token, password y password_confirmation a POST /api/v1/identity/auth/activate y dirige al login tras la confirmación.
