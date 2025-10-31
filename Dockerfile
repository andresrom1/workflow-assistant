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

# Configuración de nginx
COPY .docker/nginx.conf /etc/nginx/http.d/default.conf

# Configuración de supervisor
COPY .docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Script de arranque
COPY .docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80
CMD ["/start.sh"]