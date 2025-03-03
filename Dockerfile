# Build stage
FROM php:8.3-cli-alpine as builder

RUN apk add --no-cache --virtual .build-deps autoconf build-base git \
    && pecl install -o -f redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

WORKDIR /app
RUN git clone --depth=1 https://github.com/RobiNN1/phpCacheAdmin.git . \
    && rm -rf .git tests composer.json package.json phpstan.neon phpunit.xml README.md docker-compose.yml Dockerfile \
    && apk del .build-deps

# Final stage
FROM php:8.3-fpm-alpine

COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /app /var/www/html

RUN apk add --no-cache nginx \
    && mkdir -p /var/www/html/tmp \
    && chown -R nobody:nobody /var/www/html \
    && chmod -R 777 /var/www/html \
    && mkdir -p /run/nginx \
    && echo 'server { \
    listen 80; \
    root /var/www/html; \
    index index.php; \
    location / { \
        try_files $uri $uri/ /index.php?$query_string; \
    } \
    location ~ \.php$ { \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_index index.php; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
        include fastcgi_params; \
    } \
}' > /etc/nginx/http.d/default.conf

EXPOSE 80

CMD php-fpm -D && nginx -g 'daemon off;'
