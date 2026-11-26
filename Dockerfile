
FROM php:8.3-apache

# Инсталиране на необходимите PHP разширения
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    && docker-php-ext-install pdo_mysql intl zip opcache gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache configuration
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf

RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN sed -ri -e 's!AllowOverride None!AllowOverride All!g' \
    /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Копиране на Symfony проекта
COPY . .

# Инсталиране на Composer зависимостите
RUN composer install \
    --no-interaction \
    --optimize-autoloader \
    --ignore-platform-reqs \
    --no-scripts

# Инсталиране на ImportMap
# Временна DATABASE_URL само за Docker build
RUN DATABASE_URL="mysql://app:app@127.0.0.1:3306/app?serverVersion=8.0" \
    APP_ENV=prod \
    APP_DEBUG=0 \
    php bin/console importmap:install --no-interaction

# Създаване на необходимите директории
RUN mkdir -p var/cache var/log public/uploads \
    && chown -R www-data:www-data var public/uploads

EXPOSE 80
