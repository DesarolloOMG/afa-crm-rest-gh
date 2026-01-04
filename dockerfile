FROM php:7.3-alpine

RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    bash

RUN docker-php-ext-install \
    mbstring \
    xml \
    zip \
    pdo_mysql \
    bcmath \
    gd

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ========== USAR php.ini-production ==========
# Copiar php.ini-production como php.ini principal
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Descomentar y modificar memory_limit (línea ~400)
RUN sed -i 's/^;memory_limit\s*=.*/memory_limit = 512M/' /usr/local/etc/php/php.ini

# Descomentar otras configuraciones importantes
RUN sed -i 's/^;max_execution_time\s*=.*/max_execution_time = 300/' /usr/local/etc/php/php.ini
RUN sed -i 's/^;max_input_time\s*=.*/max_input_time = 300/' /usr/local/etc/php/php.ini
RUN sed -i 's/^;post_max_size\s*=.*/post_max_size = 100M/' /usr/local/etc/php/php.ini
RUN sed -i 's/^;upload_max_filesize\s*=.*/upload_max_filesize = 100M/' /usr/local/etc/php/php.ini

# Para desarrollo, también descomentar display_errors
# RUN sed -i 's/^;display_errors\s*=.*/display_errors = On/' /usr/local/etc/php/php.ini

RUN adduser -D -u 1000 -g www www

WORKDIR /var/www/html

COPY --chown=www:www . .

USER www

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN mkdir -p storage/framework/{sessions,views,cache} && \
    mkdir -p bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]