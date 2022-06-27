# phpCacheAdmin

Web dashboard for Redis, Memcache(d) and OPCache.

![Visitor Badge](https://visitor-badge.laobi.icu/badge?page_id=RobiNN1.phpCacheAdmin)

#### Redis

![Redis](.github/img/redis.png)

#### Memcache(d)

![Memcache(d)](.github/img/memcached.png)

#### OPCache

![OPCache](.github/img/opcache.png)

#### Server tab (default)

![Server](.github/img/server.png)

## Installation

Simply extract the content and run `composer install` before use.

If you need to customize or add servers in the configuration file, do not edit `config.dist.php` directly,
copy` config.dist.php` to `config.php` instead.

## Requirements

- PHP >= 7.4
- redis, memcache(d) or opcache php extensions (if none of them is installed, only the Server tab will be available)

## Development

For compiling Tailwind CSS run `npm install` and then
`npm run build` or `npm run watch` for auto-compiling.
