FROM php:8.3-apache

RUN apt-get update && apt-get install -y git
RUN pecl install -o -f redis && docker-php-ext-enable redis
RUN pecl install -o -f apcu && docker-php-ext-enable apcu
RUN docker-php-ext-enable opcache

WORKDIR /var/www/html

RUN git clone --depth=1 https://github.com/RobiNN1/phpCacheAdmin.git . \
    && rm -r .git tests composer.json package.json phpstan.neon phpunit.xml README.md docker-compose.yml Dockerfile

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

RUN apt-get remove --purge git -y && apt-get autoremove -y && apt-get clean

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

CMD ["apache2-foreground"]
