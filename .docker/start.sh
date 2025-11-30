#!/bin/sh
set -e

# Migraciones (runtime, con DB disponible)
php artisan migrate:fresh --force

# Cachear solo una vez (opcional pero recomendado)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Lanzar supervisord (que ya arranca nginx + php-fpm)
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf