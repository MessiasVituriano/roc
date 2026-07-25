#!/bin/sh
set -e

# O compose já espera o Postgres ficar "healthy" (depends_on), então aqui só
# garantimos o schema e os caches antes de entregar requests.
php artisan migrate --force

# Em local (APP_DEBUG=true) manter os caches desligados evita ter de limpar a
# cada troca de config; em prod cacheamos tudo para o opcache trabalhar quente.
if [ "${APP_ENV}" != "local" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    php artisan config:clear
fi

exec "$@"
