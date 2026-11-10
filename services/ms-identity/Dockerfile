# Local-development image for Identity. Build context is the monorepo root.
FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libxml2-dev libonig-dev unzip \
    && docker-php-ext-install pdo_pgsql intl zip pcntl mbstring dom \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY services/ms-identity/composer.json services/ms-identity/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY services/ms-identity/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p storage/app/identity storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
