# syntax=docker/dockerfile:1
# docker build --pull --tag phpcacheadmin-prod --file ./docker/Dockerfile ./ && docker run -p 8080:80 -it --rm phpcacheadmin-prod
FROM php:8.3-fpm-alpine

# Workdir path without trailing slash at the end
WORKDIR /app/

# Core utilites install
RUN --mount=type=cache,sharing=locked,target=/var/cache/apk/ set -eux \
  && apk add --quiet --update --no-cache \
    # `fcgi` - Required for php-fpm healthcheck |  https://github.com/renatomefi/php-fpm-healthcheck
    fcgi

# Caddy | https://caddyserver.com/
COPY --from=caddy:2-alpine --link /usr/bin/caddy /usr/local/bin/caddy
COPY ./docker/config/Caddyfile /etc/caddy/Caddyfile
ENV ACME_AGREE=true
RUN caddy validate --config /etc/caddy/Caddyfile

# Easily install PHP extension in Docker containers | https://github.com/mlocati/docker-php-extension-installer
# Extensions installed by default: Core, ctype, curl, date, dom, fileinfo, filter, hash, iconv, json, libxml, mbstring, mysqlnd, openssl, pcre, PDO, pdo_sqlite, Phar, posix, random, readline, Reflection, session, SimpleXML, sodium, SPL, sqlite3, standard, tokenizer, xml, xmlreader, xmlwriter, zlib
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN set -eux \
    && IPE_ICU_EN_ONLY=1 install-php-extensions \
        @composer-2 \
        redis \
        apcu \
        opcache

# Add PHP-FPM health check script
# More details: https://github.com/renatomefi/php-fpm-healthcheck
ADD --chmod=0705 --link https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck /usr/local/bin/php-fpm-healthcheck
# Configure PHP-FPM health check settings
RUN set -eux \
    # Enable status endpoint for healthchecks
    && echo "pm.status_path = /php-fpm-status" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    # Suppress /status path from access logs
    && echo "access.suppress_path[] = /php-fpm-status" >> /usr/local/etc/php-fpm.d/zz-docker.conf
# Cheching Caddy & php-fpm
HEALTHCHECK --interval=3s --timeout=1s --start-period=5s CMD (curl --fail http://localhost:9999/webserver-health && FCGI_STATUS_PATH=/php-fpm-status php-fpm-healthcheck) || exit 1

# Create a non-root application user
ARG APP_UID=1000
ARG APP_GID=1000
ARG APP_USER=appuser
ENV HOME=/home/${APP_USER}

RUN set -eux \
    && addgroup -g ${APP_GID} ${APP_USER} \
    && adduser -G ${APP_USER} -u ${APP_UID} -h ${HOME} -s /bin/bash -D ${APP_USER}
# Set user-specific environment paths
ENV PATH=${PATH}:${HOME}/.composer/vendor/bin:${HOME}/bin:/app/vendor/bin/

# Set PHP to production mode
RUN set -eux \
    && mv /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && rm /usr/local/etc/php/php.ini-development

# Copy PHP configuration files
COPY ./docker/config/zzz-custom-php.ini /usr/local/etc/php/conf.d/zzz-custom-php.ini
COPY ./docker/config/zzz-custom-php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom-php-fpm.conf

# Copy public files to the application directory
COPY --chown=${APP_USER}:${APP_USER} ./ ./

RUN set -eux \
    # Set the correct user permissions
    && chown -R ${APP_USER} ./ \
    # Cleanup unnecessary files
    && rm -rf ./docker/

# Switch to the non-root user
USER ${APP_USER}

RUN set -eux \
    && composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Expose ports
EXPOSE 80

# Set up the container entrypoint
COPY --chmod=0755 ./docker/etc/docker-entrypoint.sh /docker-entrypoint.sh
ENTRYPOINT ["/docker-entrypoint.sh"]
