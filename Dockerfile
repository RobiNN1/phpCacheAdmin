FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
    && pecl install redis apcu \
    && docker-php-ext-enable redis apcu opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

RUN rm -rf tests composer.json package.json phpstan.neon phpunit.xml README.md tailwind.config.js docker-compose.yml Dockerfile

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

RUN chown -R www-data:www-data /var/www/html

CMD ["apache2-foreground"]
