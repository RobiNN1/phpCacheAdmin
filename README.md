<p align="center"><img src=".github/img/logo-colored.svg" width="400" alt="Logo"></p>
<p align="center">Web dashboard for Redis, Memcache(d) and OPCache.</p>

![Visitor Badge](https://visitor-badge.laobi.icu/badge?page_id=RobiNN1.phpCacheAdmin)

| Redis                           | Memcache(d)                               |
|---------------------------------|-------------------------------------------|
| ![Redis](.github/img/redis.png) | ![Memcache(d)](.github/img/memcached.png) |

| OPCache                             | Server tab (default)                   |
|-------------------------------------|----------------------------------------|
| ![OPCache](.github/img/opcache.png) | ![Memcache(d)](.github/img/server.png) |

## Installation

Simply extract the content. Optional but highly recommended, run `composer install` before use.

If you need to customize or add servers in the configuration file, do not edit `config.dist.php` directly,
copy `config.dist.php` to `config.php` instead.

## Updating

Replace all files and delete the `cache` folder.

> **Note**
>
> `Cache` folder contains optimized Twig templates for faster page loading.
> However, when changes are made to the Twig files, the cache will not change
> (unless you have Twig debugging enabled or delete folder).

## Docker

https://hub.docker.com/r/robinn/phpcacheadmin

Run with single command:

```
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

> **Note**
>
> Is not required to have both Redis and Memcached.

#### Environment variables

Redis:

- `PCA_REDIS_0_NAME` The server name for info panel, useful when you have multiple servers added (Optional, default name is Localhost)
- `PCA_REDIS_0_HOST` Redis server host.
- `PCA_REDIS_0_PORT` Redis server port (Optional, default is 6379)
- `PCA_REDIS_0_DATABASE` Redis database (Optional, default is 0)
- `PCA_REDIS_0_PASSWORD` (Optional, empty by default)

Memcached:

- `PCA_MEMCACHED_0_NAME` The server name for info panel, useful when you have multiple servers added (Optional, default name is Localhost)
- `PCA_MEMCACHED_0_HOST` Memcached server host.
- `PCA_MEMCACHED_0_PORT` Memcached server port (Optional, default is 11211)

To add another server, add the same environment variables, but change 0 to 1 (2 for third server and so on).

## Requirements

- PHP >= 7.4
- redis, memcache(d) or opcache php extensions (if none of them is installed, only the Server tab will be available)

## Development

For compiling Tailwind CSS run `npm install` and then
`npm run build` or `npm run watch` for auto-compiling.
