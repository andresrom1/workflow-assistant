#!/bin/sh
set -e

# 1. Configuración Dinámica de Supervisor
# Creamos un archivo de configuración extra para que Supervisor gestione Reverb y el Worker.
# Esto garantiza que si se caen, se reinicien automáticamente.
echo "[program:reverb]
command=php artisan reverb:start
autostart=true
autorestart=true
stderr_logfile=/dev/stderr
stdout_logfile=/dev/stdout

[program:worker]
command=php artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/dev/stderr
stdout_logfile=/dev/stdout" > /etc/supervisor/conf.d/laravel-worker.conf

# 2. Migraciones (Producción)
# Usamos --force para evitar preguntas. 
# IMPORTANTE: Cambiamos :fresh por migrate estándar para NO borrar datos.
php artisan migrate:fresh --force

# 3. Optimización de Caché
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 4. Iniciar Supervisor
# Supervisor leerá el archivo laravel-worker.conf que creamos arriba
# y arrancará Nginx, PHP-FPM, Reverb y el Queue Worker.
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf