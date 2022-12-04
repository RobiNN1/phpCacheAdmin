FROM php:8.1-apache

RUN apt-get update

RUN apt-get install -y git

RUN pecl install redis && docker-php-ext-enable redis

RUN apt-get install -y libz-dev libmemcached-dev && pecl install memcached && docker-php-ext-enable memcached

RUN docker-php-ext-enable opcache

RUN pecl install apcu && docker-php-ext-enable apcu

RUN rm -rf /tmp/pear

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html
RUN chmod 777 /var/www/html

RUN git clone --depth=1 https://github.com/RobiNN1/phpCacheAdmin.git .

CMD apache2-foreground
