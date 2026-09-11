FROM php:8.2-apache

RUN apt-get update             && docker-php-ext-install -j$(nproc) pdo_mysql             && a2enmod headers access_compat             && sed -ri 's!^Listen 80$!Listen 127.0.0.1:18083!' /etc/apache2/ports.conf             && sed -ri 's!<VirtualHost \*:80>!<VirtualHost 127.0.0.1:18083>!' /etc/apache2/sites-available/000-default.conf             && sed -ri 's!www-data!daemon!g' /etc/apache2/envvars             && rm -rf /var/lib/apt/lists/*

COPY apache-appdomicilios.conf /etc/apache2/conf-enabled/apache-appdomicilios.conf
COPY php-appdomicilios.ini /usr/local/etc/php/conf.d/99-appdomicilios.ini

WORKDIR /var/www/html

EXPOSE 18083
