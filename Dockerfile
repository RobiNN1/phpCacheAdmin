FROM php:8.3-apache

RUN pecl install redis && docker-php-ext-enable redis
RUN apt install -y libz-dev libssl-dev libmemcached-dev && pecl install memcached && docker-php-ext-enable memcached
RUN docker-php-ext-enable opcache
RUN pecl install apcu && docker-php-ext-enable apcu

WORKDIR /var/www/html

RUN rm -rf tests composer.json package.json phpstan.neon phpunit.xml README.md docker-compose.yml Dockerfile

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

RUN chown -R www-data:www-data /var/www/html

CMD ["apache2-foreground"]
