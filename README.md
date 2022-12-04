<p align="center"><img src=".github/img/logo-colored.svg" width="400" alt="Logo"></p>
<p align="center">A web dashboard for your favorite caching system.</p>

![Visitor Badge](https://visitor-badge.laobi.icu/badge?page_id=RobiNN1.phpCacheAdmin)

<table>
  <tr>
    <td><img alt="Redis" src=".github/img/redis.png"></td>
    <td><img alt="Memcached" src=".github/img/memcached.png"></td>
  </tr>
  <tr>
    <td><img alt="OPCache" src=".github/img/opcache.png"></td>
    <td><img alt="APCu" src=".github/img/apcu.png"></td>
  </tr>
</table>

<div align="center">
  <img width="450" alt="Server tab (default)" src=".github/img/server.png">

  Server tab (default)
</div>

## Installation

Simply extract the content. Optional but highly recommended, run `composer install` before use.

If you need to customize or add servers to the configuration file,
do not edit `config.dist.php` directly, but copy it to `config.php`.

## Updating

Replace all files and delete the `cache` folder.

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
    #volumes: # If you want to use config.php instead of ENV variables
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

> **Note**
>
> It is not required to have both Redis and Memcached.

#### Environment variables

Redis:

- `PCA_REDIS_0_NAME` The server name for an info panel. Optional.
- `PCA_REDIS_0_HOST` Redis host. Optional, when a `path` is specified.
- `PCA_REDIS_0_PORT` Redis port. Optional, when the default port is used.
- `PCA_REDIS_0_DATABASE` Redis database. Optional, default database.
- `PCA_REDIS_0_USERNAME` Redis username. Optional, requires Redis >= 6.0.
- `PCA_REDIS_0_PASSWORD` Redis password. Optional.
- `PCA_REDIS_0_PATH` Redis unix domain socket. Optional.
- `PCA_REDIS_0_DATABASES` Optional, number of databases (use this if the `CONFIG` command is disabled).

Memcached:

- `PCA_MEMCACHED_0_NAME` The server name for an info panel. Optional.
- `PCA_MEMCACHED_0_HOST` Memcached host. Optional, when a `path` is specified.
- `PCA_MEMCACHED_0_PORT` Memcached port. Optional, when the default port is used.
- `PCA_MEMCACHED_0_PATH` Memcached unix domain socket. Optional.

> To add another server, add the same environment variables, but change 0 to 1 (2 for third server and so on).
>
> All keys from the config file are supported ENV variables, they just must start with PCA_ prefix.

## Requirements

- PHP >= 7.4
- redis, memcache(d), opcache or apcu php extensions (if none of them is installed, only the Server tab will be available)
- Redis server >= 3.0.0
- Memcached server >= 1.4.18

> **Note**
> 
> For better performance, always use extensions, however:
> - If the Redis extension is not installed, the system will use a Predis client (if you are using composer, install Predis manually via `composer require predis/predis`).
> - If the Memcache(d) extension is not installed, the system will use a custom PHPMem client.

## Custom Dashboards

- [FileCache](https://github.com/RobiNN1/FileCache-Dashboard) ([`robinn/cache`](https://github.com/RobiNN1/Cache)) dashboard.

## Testing

PHPUnit

```
composer test
```

PHPStan

```
composer phpstan
```

## Development

For compiling Tailwind CSS run `npm install` and then
`npm run build` or `npm run watch` for auto-compiling.

<!-- Font used in logo Arial Rounded MT Bold -->
