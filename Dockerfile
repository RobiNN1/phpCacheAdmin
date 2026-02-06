# Build stage
FROM php:8.5-cli-alpine AS builder

RUN apk add --no-cache --virtual .build-deps autoconf build-base git \
    && pecl install -o -f redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

WORKDIR /app
RUN git clone --depth=1 https://github.com/RobiNN1/phpCacheAdmin.git . \
    && rm -rf .git tests composer.json package.json phpstan.neon phpunit.xml README.md docker-compose.yml Dockerfile \
    && apk del .build-deps

# Final stage
FROM php:8.5-fpm-alpine

COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /app /var/www/html

RUN apk add --no-cache nginx gettext \
    && mkdir -p /var/www/html/tmp /run/nginx /var/cache/nginx /var/log/nginx \
    && chmod -R 777 /var/www/html /run/nginx /var/cache/nginx /var/log/nginx \
    && sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf

ENV PCA_NGINX_PORT=80

# NGINX config template
RUN printf 'server {\n\
    listen ${PCA_NGINX_PORT};\n\
    root /var/www/html;\n\
    index index.php;\n\
    location / {\n\
        try_files $uri $uri/ /index.php$is_args$args;\n\
    }\n\
    location ~ \\.php$ {\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_index index.php;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
        include fastcgi_params;\n\
    }\n\
}\n' > /etc/nginx/http.d/default.conf.template

CMD envsubst '${PCA_NGINX_PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf \
    && php-fpm -D \
    && nginx -g 'daemon off;'
