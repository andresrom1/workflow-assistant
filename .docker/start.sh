#!/bin/sh
set -e

# 1. Configuración Dinámica de Supervisor
# Creamos un archivo de configuración extra para que Supervisor gestione los Workers.
# Esto garantiza que si se caen, se reinicien automáticamente.
mkdir -p /var/log/supervisor

echo "[program:worker]
command=php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=60 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-err.log
stdout_logfile=/var/log/supervisor/worker-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:worker-whatsapp-ai]
command=php artisan queue:work --queue=whatsapp-ai --sleep=3 --tries=3 --timeout=180 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-whatsapp-ai-err.log
stdout_logfile=/var/log/supervisor/worker-whatsapp-ai-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:worker-whatsapp-outbound]
command=php artisan queue:work --queue=whatsapp-outbound --sleep=3 --tries=5 --timeout=30 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-whatsapp-outbound-err.log
stdout_logfile=/var/log/supervisor/worker-whatsapp-outbound-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:worker-quotes]
command=php artisan queue:work --queue=quotes --sleep=3 --tries=2 --timeout=360 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-quotes-err.log
stdout_logfile=/var/log/supervisor/worker-quotes-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:worker-media]
command=php artisan queue:work --queue=media --sleep=3 --tries=3 --timeout=120 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-media-err.log
stdout_logfile=/var/log/supervisor/worker-media-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:worker-documents]
command=php artisan queue:work --queue=documents --sleep=5 --tries=3 --timeout=300 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-documents-err.log
stdout_logfile=/var/log/supervisor/worker-documents-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:scheduler]
command=php artisan schedule:work
autostart=true
autorestart=true
stopwaitsecs=60
stderr_logfile=/var/log/supervisor/scheduler-err.log
stdout_logfile=/var/log/supervisor/scheduler-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB" > /etc/supervisor/conf.d/laravel-worker.conf

# 2. Migraciones (Producción)
# Usamos --force para evitar preguntas. 
# IMPORTANTE: Cambiamos :fresh por migrate estándar para NO borrar datos.
php artisan migrate --force

# 3. Optimización de Caché
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 3b. Permisos de runtime
# Los workers corren como root (supervisord user=root) mientras php-fpm/nginx corren como www.
# Si un worker es el primero en escribir storage/logs/laravel.log, el archivo nace root:root 644
# y las peticiones web (www) ya no pueden escribirlo → cualquier Log:: en una request web
# revienta con un 500 SIN dejar rastro (falla justo al intentar loguear el error).
# Garantizamos que el árbol de runtime pertenezca a www antes de arrancar los procesos.
mkdir -p storage/logs
touch storage/logs/laravel.log
chown -R www:www storage bootstrap/cache

# 4. Iniciar Supervisor
# Supervisor leerá el archivo laravel-worker.conf que creamos arriba
# y arrancará Nginx, PHP-FPM y los Queue Workers.
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf