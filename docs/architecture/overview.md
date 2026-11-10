# Arquitectura actual

El Gateway recibe trafico en 4000 y enruta a cinco APIs. CORS usa una lista explicita de origenes. Cada servicio valida autenticacion y autorizacion; el Gateway bloquea rutas internas y elimina cabeceras de identidad no confiables.

Identity administra usuarios, empresas, sesiones, permisos, suscripciones y cupos sobre PostgreSQL. Agricultural administra productores, lotes, inspecciones, evidencias y dictamenes sobre su propia base. Usa Identity para identidad/cupos e IA para inferencia; su worker procesa trabajos y outbox. Notifications persiste eventos deduplicados, bandeja, preferencias y entregas; su worker consulta destinatarios en Identity y entrega correo cuando SMTP esta habilitado.

Billing conserva ordenes, pagos, reembolsos, webhooks y outbox en MongoDB. Su outbox envia eventos firmados a Identity para aplicar derechos sobre ordenes conocidas. Agricultural envia eventos firmados a Notifications. No existe fanout automatico de eventos Identity/Billing hacia Notifications. IA persiste ejecuciones tecnicas con PostgreSQL/Alembic y usa Roboflow/Cloudinary.

compose.yaml es la entrada local completa, con Dockerfiles especificos de Identity, Agricultural y Notifications. Los workers agricola y de notificaciones arrancan por defecto. El worker de correo Identity usa perfil identity-mail; Billing usa su temporizador o un worker bajo perfil events, desactivando antes el temporizador del API. No hay broker ni Redis: los trabajos y outboxes se persisten en las bases de sus propietarios.

Las claves RSA de Identity y evidencias agricolas usan volumenes persistentes. Las bases no publican puertos al host y las APIs se publican en loopback. Las migraciones Laravel/Notifications son pasos explicitos previos al arranque de workers; IA aplica Alembic al iniciar. Ver README raiz para el orden de aprovisionamiento.

La configuracion integra contratos existentes; no acredita arranque, migraciones ni flujos de negocio ejecutados. Produccion requiere servidores y supervision adecuados, secretos, HTTPS y validacion operativa.
