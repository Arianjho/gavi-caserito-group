# syntax=docker/dockerfile:1
# API Laravel de Gavi Caserito sobre FrankenPHP (Caddy + PHP en un solo proceso).
# La misma imagen sirve para la API, la cola y el planificador; cambia solo el
# comando (ver docker-compose.prod.yml).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction

FROM dunglas/frankenphp:1-php8.3-alpine
RUN install-php-extensions pdo_pgsql bcmath intl opcache pcntl
ENV SERVER_NAME=:8080 \
    APP_ENV=production \
    APP_DEBUG=false
WORKDIR /app
COPY --from=vendor /app /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
