FROM php:8.2-apache

# Install PostgreSQL PDO extension for Supabase
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache rewrite engine
RUN a2enmod rewrite

# Copy project files into Apache web root
COPY . /var/www/html/

RUN mkdir -p storage && chown -R www-data:www-data storage && chmod -R 775 storage

EXPOSE 80
