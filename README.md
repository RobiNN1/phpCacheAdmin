<p align="center"><img src=".github/img/logo-colored.svg" width="400" alt="Logo"></p>
<p align="center">A web dashboard for your favorite caching system.</p>

![Visitor Badge](https://visitor-badge.laobi.icu/badge?page_id=RobiNN1.phpCacheAdmin)

<table>
  <tr>
    <td><img alt="Redis" src=".github/img/redis.png"></td>
    <td><img alt="Memcached" src=".github/img/memcached.png"></td>
    <td><img alt="OPCache" src=".github/img/opcache.png"></td>
    <td><img alt="APCu" src=".github/img/apcu.png"></td>
  </tr>
</table>

## Installation

Just unzip the archive and launch index.php in a web browser. No installation is required.
However, it is highly recommended (although not required) to run `composer install`.

If you use the defaults (e.g. Redis, Memcached servers), everything should work out of the box.
To customize the configuration, do not edit `config.dist.php` directly, but copy it into `config.php`.

## Updating

Replace all files and delete the `tmp` folder (this folder contains only compiled Twig templates).

## Docker

https://hub.docker.com/r/robinn/phpcacheadmin

Run with single command:

```bash
docker run -p 8080:80 -d --name phpcacheadmin -e "PCA_REDIS_0_HOST=redis_host" -e "PCA_REDIS_0_PORT=6379" -e "PCA_MEMCACHED_0_HOST=memcached_host" -e "PCA_MEMCACHED_0_PORT=11211" robinn/phpcacheadmin
```

Or simply use it in **docker-compose.yml**

```yaml
version: '3'
services:
  phpcacheadmin:
    image: robinn/phpcacheadmin
    ports:
      - "8080:80"
    #volumes:
    # If you want to use config.php instead of ENV variables
    #  - "./config.php:/var/www/html/config.php"
    environment:
      - PCA_REDIS_0_HOST=redis
      - PCA_REDIS_0_PORT=6379
      - PCA_MEMCACHED_0_HOST=memcached
      - PCA_MEMCACHED_0_PORT=11211
    links:
      - redis
      - memcached
  redis:
    image: redis
  memcached:
    image: memcached
```

#### Environment variables

All keys from the [config](https://github.com/RobiNN1/phpCacheAdmin/blob/master/config.dist.php) file are supported ENV variables,
they just must start with `PCA_` prefix.

Options with an array can be set using "dot notation" but use `_` instead of a dot.
Or you can even use JSON (e.g. Redis SSL option).

Redis:

- `PCA_REDIS_0_NAME` The server name (optional).
- `PCA_REDIS_0_HOST` Optional when a path is specified.
- `PCA_REDIS_0_PORT` Optional when the default port is used.
- `PCA_REDIS_0_SCHEME` Connection scheme (optional). If you need a TLS connection, set it to `tls`.
- `PCA_REDIS_0_SSL` [SSL options](https://www.php.net/manual/en/context.ssl.php) for TLS. Requires Redis >= 6.0 (optional). You can set value as JSON `{"local_cert":"path/to/redis.crt","local_pk":"path/to/redis.key","cafile":"path/to/ca.crt","verify_peer_name":false}`.
- `PCA_REDIS_0_DATABASE` Default database (optional).
- `PCA_REDIS_0_USERNAME` ACL - requires Redis >= 6.0 (optional).
- `PCA_REDIS_0_PASSWORD` Optional.
- `PCA_REDIS_0_AUTHFILE` File with a password, e.g. Docker secrets (optional).
- `PCA_REDIS_0_PATH` Unix domain socket (optional).
- `PCA_REDIS_0_DATABASES` Number of databases, use this if the CONFIG command is disabled (optional).
- `PCA_REDIS_0_SCANSIZE` Number of keys, the server will use the SCAN command instead of KEYS (optional).

Memcached:

- `PCA_MEMCACHED_0_NAME` The server name (optional).
- `PCA_MEMCACHED_0_HOST` Optional when a path is specified.
- `PCA_MEMCACHED_0_PORT` Optional when the default port is used.
- `PCA_MEMCACHED_0_PATH` Unix domain socket (optional).

> To add another server, add the same environment variables, but change `0` to `1` (`2` for third server and so on).

## Requirements

- PHP >= 7.4
- Redis server >= 3.0.0
- Memcached server >= 1.4.31. If you do not see the keys, you need to enable `lru_crawler`. SASL is not supported because there is no way to get the keys.

> It is not necessary to have all dashboards enabled.

## Custom Dashboards

- [FileCache](https://github.com/RobiNN1/FileCache-Dashboard) ([`robinn/cache`](https://github.com/RobiNN1/Cache)) dashboard.

<!-- Font used in logo Arial Rounded MT Bold -->
