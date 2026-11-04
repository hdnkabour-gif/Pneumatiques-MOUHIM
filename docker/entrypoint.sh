#!/bin/sh
# Rôles du conteneur : web (défaut), scheduler, worker.
set -e

export SERVER_NAME=":${PORT:-8080}"

if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

case "$1" in
    web)
        php artisan migrate --force
        # Reference data only (roles, permissions, sites): idempotent, never a user account.
        php artisan db:seed --force
        exec frankenphp php-server --root /app/public --listen "$SERVER_NAME"
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    worker)
        exec php artisan queue:work --tries=3 --max-time=3600
        ;;
    *)
        exec "$@"
        ;;
esac
