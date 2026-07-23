FROM alpine:3.23

RUN apk add --no-cache nginx php85-fpm php85-session php85-phar php85-mbstring php85-ctype php85-iconv php85-openssl php85-pdo php85-pdo_sqlite \
    && adduser -u 82 -D -S -H -s /sbin/nologin -G www-data www-data \
    && mkdir -p /var/www/html/tmp \
    && chown www-data:www-data /var/www/html/tmp

RUN printf '[www]\n\
user = www-data\n\
group = www-data\n\
listen = 127.0.0.1:9000\n\
clear_env = no\n\
ping.path = /ping\n' > /etc/php85/php-fpm.d/zzz-pca.conf

RUN printf 'server {\n\
    listen ${PCA_NGINX_PORT};\n\
    root /var/www/html;\n\
    index index.php;\n\
    location / {\n\
        try_files $uri $uri/ /index.php$is_args$args;\n\
    }\n\
    location = /ping {\n\
        allow 127.0.0.1;\n\
        deny all;\n\
        access_log off;\n\
        include fastcgi_params;\n\
        fastcgi_param SCRIPT_FILENAME $fastcgi_script_name;\n\
        fastcgi_pass 127.0.0.1:9000;\n\
    }\n\
    location ~ \\.php$ {\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_index index.php;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
        include fastcgi_params;\n\
    }\n\
}\n' > /etc/nginx/http.d/default.conf.template

COPY --chmod=755 index.php config.dist.php predis.phar twig.phar /var/www/html/
COPY --chmod=755 src /var/www/html/src
COPY --chmod=755 templates /var/www/html/templates
COPY --chmod=755 assets /var/www/html/assets

ENV PCA_NGINX_PORT=80

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
    CMD wget -q -O /dev/null "http://127.0.0.1:${PCA_NGINX_PORT}/ping" || exit 1

STOPSIGNAL SIGQUIT

CMD ["/bin/sh", "-c", "sed \"s|\\${PCA_NGINX_PORT}|${PCA_NGINX_PORT}|g\" /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf && php-fpm85 -D && exec nginx -g 'daemon off;'"]
