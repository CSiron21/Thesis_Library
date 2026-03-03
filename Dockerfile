FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y default-mysql-client && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize=64M\npost_max_size=72M\nmemory_limit=256M\nmax_execution_time=300" \
    > /usr/local/etc/php/conf.d/uploads.ini

# Copy application source code into the image for production
COPY src/ /var/www/html/

# Set proper permissions for the web server
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
