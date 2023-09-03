FROM php:8.2-apache

RUN apt update

RUN apt install -y git

RUN pecl install redis && docker-php-ext-enable redis

RUN apt install -y libz-dev libmemcached-dev libssl-dev && pecl install memcached && docker-php-ext-enable memcached

RUN docker-php-ext-enable opcache

RUN pecl install apcu && docker-php-ext-enable apcu

RUN rm -rf /tmp/pear

WORKDIR /var/www/html
RUN chmod 777 /var/www/html

RUN git clone --depth=1 https://github.com/RobiNN1/phpCacheAdmin.git .
RUN rm -r .git tests composer.json package.json phpstan.neon phpunit.xml README.md tailwind.config.js docker-compose.yml Dockerfile
RUN apt remove git -y && apt autoremove -y

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf # fix for apache

CMD apache2-foreground
