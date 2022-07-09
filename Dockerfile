FROM php:8.1-apache

RUN apt-get update && apt-get install -y git

RUN pecl install redis && docker-php-ext-enable redis

RUN apt-get install -y libz-dev libmemcached-dev \
    && pecl install memcached && docker-php-ext-enable memcached

RUN docker-php-ext-enable opcache

RUN rm -rf /tmp/pear

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www
RUN git clone https://github.com/RobiNN1/phpCacheAdmin.git html

RUN chmod 777 /var/www/html

WORKDIR /var/www/html

CMD apache2-foreground
