FROM php:8.3-apache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

RUN a2enmod rewrite \
    && docker-php-ext-install pdo pdo_mysql \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
