#!/bin/sh
set -e

# 1. Configuración Dinámica de Supervisor
# Creamos un archivo de configuración extra para que Supervisor gestione los Workers.
# Esto garantiza que si se caen, se reinicien automáticamente.
#
# ---------------------------------------------------------------------------------------
# TRES workers residentes, no seis. Lo que cuesta plata no es la cantidad de COLAS —el
# nombre de la cola es una etiqueta de texto en una columna— sino la cantidad de procesos
# PHP residentes, cada uno con el framework y el AI SDK cargados en memoria para siempre.
#
#   worker-ai        whatsapp-ai                        el turno del LLM, 4-95s. Aislado.
#   worker-realtime  default, whatsapp-outbound, media  todo corto y de cara al cliente.
#   worker-quotes    quotes                             30-360s contra Visred. Aislado.
#
# El cuarto grupo, `background` (emisión, facturación, extracción de PDF, limpieza), NO
# tiene worker residente: lo levanta el scheduler bajo demanda con `--stop-when-empty`,
# que termina el proceso apenas vacía la cola. Ver routes/console.php.
#
# Para volver a un worker residente de background —si la latencia de hasta 60s en la
# emisión molesta— se agrega acá un `[program:worker-background]` con `--sleep=20` y se
# saca la entrada del scheduler. Cuesta ~90 MB residentes.
#
# ---------------------------------------------------------------------------------------
# El PRIMER argumento de `queue:work` es la CONEXIÓN, y no es opcional.
#
# `retry_after` (cada cuántos segundos la cola da por abandonado un job reservado y lo
# vuelve a entregar) NO viaja con el job: lo aplica la conexión del worker que lo SACA.
# Sin el argumento, Laravel cae a `queue.default` y todos los workers terminan usando el
# `retry_after` de `database`, ignorando los valores de config/queue.php.
#
# INVARIANTE por worker:  retry_after de su conexión  >  su --timeout
#
#   database        200  >  60
#   database_ai     200  >  180
#   database_quotes 420  >  360
#   database_long   360  >  300   (el de background, en el scheduler)
#
# `--timeout` y `--tries` acá son sólo el techo de seguridad: cada job declara los suyos
# (`public int $timeout` / `$tries`) y ésos son los que manda Laravel. Los cuatro
# invariantes los verifica tests/Feature/Queue/WorkerConfigTest.php.
#
# `--max-time` va escalonado a propósito: con el mismo valor los tres arrancan juntos y
# reinician juntos, con un pico de arranque en frío sincronizado cada hora.
# ---------------------------------------------------------------------------------------
mkdir -p /var/log/supervisor

echo "[program:worker-ai]
command=php artisan queue:work database_ai --queue=whatsapp-ai --sleep=3 --tries=3 --timeout=180 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-ai-err.log
stdout_logfile=/var/log/supervisor/worker-ai-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:worker-realtime]
command=php artisan queue:work database --queue=default,whatsapp-outbound,media --sleep=3 --tries=3 --timeout=60 --max-time=3900
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-realtime-err.log
stdout_logfile=/var/log/supervisor/worker-realtime-out.log
stdout_logfile_maxbytes=1MB
stderr_logfile_maxbytes=1MB

[program:worker-quotes]
command=php artisan queue:work database_quotes --queue=quotes --sleep=3 --tries=2 --timeout=360 --max-time=4200
autostart=true
autorestart=true
stopwaitsecs=3600
stderr_logfile=/var/log/supervisor/worker-quotes-err.log
stdout_logfile=/var/log/supervisor/worker-quotes-out.log
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
