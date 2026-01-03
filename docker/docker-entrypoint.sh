#!/bin/sh
set -e

# Crear directorios necesarios si no existen
mkdir -p storage/framework/{sessions,views,cache}
mkdir -p bootstrap/cache

# Ajustar permisos
chown -R www:www /var/www/html
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Limpiar cache de configuración
if [ -f artisan ]; then
    php artisan config:clear || true
    php artisan cache:clear || true
    php artisan optimize || true
fi

# Iniciar Apache
exec "$@"