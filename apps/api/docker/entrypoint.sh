#!/bin/sh
# Prepara la aplicación al arrancar y luego ejecuta el comando recibido.
set -e
cd /app

if [ -z "$APP_KEY" ]; then
  echo "Falta APP_KEY. Genera una con: php artisan key:generate --show" >&2
  exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan event:cache

# Solo el servicio que lo pida (RUN_MIGRATIONS=true) aplica migraciones.
if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

exec "$@"
