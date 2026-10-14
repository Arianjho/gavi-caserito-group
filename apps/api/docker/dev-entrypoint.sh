#!/bin/sh
# Arranque de desarrollo: instala dependencias si faltan, prepara .env y
# (solo el servicio con RUN_MIGRATIONS=true) migra y siembra datos de prueba.
set -e
cd /app

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --force
fi

php artisan config:clear

if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
  php artisan db:seed --force
fi

exec "$@"
