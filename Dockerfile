FROM php:8.4-fpm-alpine

# 1. Crear usuario antes que nada
RUN addgroup -g 1000 www && adduser -u 1000 -G www -s /bin/sh -D www

# 2. Dependencias del sistema
#RUN apk add --no-cache \ # Vammos a probar si al cachear se despliega mas rapido
RUN apk add --no-cache \
nginx supervisor curl zip unzip git sqlite libzip-dev linux-headers \
&& docker-php-ext-install pdo pdo_mysql zip

# 3. Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 4. WORKDIR con propietario www
WORKDIR /var/www
RUN chown www:www /var/www

# Cambiamos owner ANTES de copiar
COPY --chown=www:www . .

# 5. Instalar dependencias de Laravel (ya como www)
USER www
RUN composer install --no-dev --optimize-autoloader --no-scripts

# 6. Crear dirs de runtime y dar permisos
RUN mkdir -p storage/framework/{cache,sessions,views,data} \
            bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# 7. Volver a root para el resto de configs
USER root

# 8. Configs de nginx, supervisor, php-fpm, etc.
COPY --chown=root:root .docker/nginx.conf /etc/nginx/http.d/default.conf
COPY --chown=root:root .docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chown=root:root .docker/start.sh /start.sh
RUN chmod +x /start.sh

# Usuarios de servicios
RUN sed -i 's/user = www-data/user = www/g' /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's/group = www-data/group = www/g' /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's/user nginx;/user www;/g' /etc/nginx/nginx.conf

EXPOSE 80
CMD ["/start.sh"]