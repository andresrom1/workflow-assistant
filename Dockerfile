FROM php:8.4-fpm-alpine

# Dependencias del sistema
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    zip \
    unzip \
    git \
    sqlite \
    libzip-dev \
    linux-headers \
    && docker-php-ext-install pdo pdo_mysql zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuario www-data
RUN addgroup -g 1000 www && adduser -u 1000 -G www -s /bin/sh -D www

# Copiar código
WORKDIR /var/www
COPY . .

# Instalar dependencias de Laravel
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Permisos
RUN chown -R www:www /var/www/storage /var/www/bootstrap/cache

# Crear dirs que Laravel necesita
RUN mkdir -p /var/www/storage/framework/{cache,sessions,views,data} \
    /var/www/bootstrap/cache \
    && chown -R www:www /var/www \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Configuración de nginx
COPY .docker/nginx.conf /etc/nginx/http.d/default.conf

# Configuración de supervisor
COPY .docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Script de arranque
COPY .docker/start.sh /start.sh
RUN chmod +x /start.sh

# Cambiar usuario de PHP-FPM
RUN sed -i 's/user = www-data/user = www/g' /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's/group = www-data/group = www/g' /usr/local/etc/php-fpm.d/www.conf

# Cambiar usuario de nginx
RUN sed -i 's/user nginx;/user www;/g' /etc/nginx/nginx.conf

EXPOSE 80
CMD ["/start.sh"]