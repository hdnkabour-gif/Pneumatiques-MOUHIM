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
        # First owner from OWNER_* variables (no-op when absent or an owner exists).
        # A rejected value (weak password…) is reported in the logs without blocking start-up.
        php artisan app:create-owner --from-env || echo "app:create-owner --from-env failed: see the messages above"
        # Uploaded files (logo, photos) live in storage/app/public (the Railway volume) and are
        # served as /storage through public/storage. The link is not in the image: create it at
        # every start (--force only replaces an existing symbolic link, never a real folder).
        php artisan storage:link --force || echo "storage:link failed: uploaded images will not be displayed"
        # Scheduled tasks (daily backup…) run inside the web container unless a
        # dedicated "scheduler" service is used (RUN_SCHEDULER=false on web then).
        if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
            php artisan schedule:work &
        fi
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
