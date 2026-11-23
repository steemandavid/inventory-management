# Local test server that mimics LWS: PHP 8.4 + Apache with .htaccess support.
FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libicu-dev \
 && docker-php-ext-install pdo_mysql zip intl \
 && a2enmod rewrite headers \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*
