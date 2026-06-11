FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev unzip git curl \
    && docker-php-ext-install pdo pdo_mysql mysqli zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

RUN echo "upload_max_filesize = 25M\npost_max_size = 30M\nmemory_limit = 256M\nmax_file_uploads = 10\nmax_execution_time = 60\ndisplay_errors = On\nerror_reporting = E_ALL" \
    > /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html
