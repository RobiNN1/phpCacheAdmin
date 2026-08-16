<p align="center"><img src=".github/img/logo.svg" width="400" alt="Logo"></p>
<p align="center">Web GUI for managing Redis, Valkey, KeyDB, Memcached, APCu, OPCache, and Realpath with data management.</p>
<p align="center"><strong><a href="https://phpcacheadmin.com/">phpcacheadmin.com</a></strong></p>
<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/RobiNN1/phpCacheAdmin/refs/heads/docs/assets/img/preview/redis-dark.webp">
    <img alt="Preview" src="https://raw.githubusercontent.com/RobiNN1/phpCacheAdmin/refs/heads/docs/assets/img/preview/redis-light.webp" width="500px">
  </picture>
</p>

![Visitor Badge](https://visitor-badge.laobi.icu/badge?page_id=RobiNN1.phpCacheAdmin)
![Docker Pulls](https://img.shields.io/docker/pulls/robinn/phpcacheadmin)

## Features

### General

- Clean, responsive interface with a **Dark Mode**.
- Switch between multiple configured servers for Redis, Memcached.
- **Table or tree view** of the key list, grouped by a configurable separator.
- Back up and restore your data.
- Quickly find keys across your cache.
- View any key's details in a **modal** without leaving the list.
- View a value as **formatted, raw, or a hex dump**.
- **Converters** (gzip, zlib, ...) and **formatters** (unserialize) are applied automatically when a value is displayed.
- Optional **login page** with basic authentication (enabled by defining users in `authusers`).
- **Read-only mode** that hides and blocks everything destructive.
- **No composer required to run**.
- **Docker ready**.

### Redis

- Works with **Valkey** and **KeyDB** as well.
- Works with both **phpredis** and **Predis** clients.
- View, add, edit, and delete keys. Supports all Redis data types, including **vector sets**.
- Search within a key's sub-items (hash fields, set/list/sorted set members).
- **Stream consumer groups** with their consumers, pending entries, lag, and the oldest entry still waiting for an acknowledgement.
- **Analysis** of the keyspace, next to the size distribution of every key in the database that the server tracks on its own (Redis >= 8).
- **Latency** with the events recorded by the latency monitor and their history, per-command latency percentiles, and the advice of LATENCY / MEMORY DOCTOR.
- **Clients** list of the connected clients with their idle time, memory and last command, and the option to disconnect any of them.
- **Profiler** to watch commands as they run, live, via MONITOR. Watches every node at once in a cluster.
- **Console** for running Redis commands interactively, with a persistent per-server command history.
- **PUB/SUB** support to browse channels, subscribe and publish messages.
- **Metrics** with **health checks** for memory, hit rate, evictions, clients, persistence and replication.
- **Cluster support**.
- **Sentinel support**.
- Supports ACL.
- Detailed server statistics including command calls, memory usage, uptime, connected clients, and general info.
- View the Redis slowlog to debug performance issues.
- Supports both SCAN and KEYS commands for retrieving keys.

### Memcached

- Uses a custom client, so **no memcache(d) extension** is required.
- View, add, edit, and delete keys.
- **Analysis** of the keyspace, with the size distribution of every item in the cache when the server is started with `-o track_sizes`.
- **Watcher** to see what the server does with each key as it happens – reads, writes, evictions, deletions and
  connections, straight from the server's own log stream. Only what the server is new enough for is offered
  (watchers need 1.4.26, connection events 1.6.11, deletions 1.6.20).
- **Connections** list of the open connections with their state and idle time.
- **Console** for running Memcached commands interactively, with a persistent per-server command history.
- **Metrics** with **health checks**.
- Server Stats including uptime, memory usage, connections, and more.
- Slabs & Items info.
- Commands Stats.

### PHP Caches

- **APCu**:
    - View, edit, and delete user-cached entries.
    - View cache information and memory usage statistics.
    - **Analysis** of the cache, with a **memory map** of each shared memory segment that shows whether the free memory is one usable piece or crumbs between entries.
    - **Health** checks.
- **OPcache**:
    - View and invalidate cached scripts.
    - Get statistics on memory usage, hit rates, and cached keys.
    - **Treemap** visualization of cached scripts by memory usage.
    - **Preload** list of the files kept in memory for the life of the server.
    - **Warmup** that compiles a whole directory into the cache. Useful right after a deployment.
    - **Health** checks.
- **Realpath Cache**:
    - View and clear PHP's realpath cache entries, with their TTL and memory usage.
    - **Health** checks for the cache size, expired entries and the TTL, including a warning that `open_basedir` turns the cache off entirely.

There is also a **Server** dashboard for a quick look at the machine phpCacheAdmin runs on - PHP version and
configuration, loaded extensions, `phpinfo()`, and CPU, RAM and disk usage.

## Installation

Download the [latest release](https://github.com/RobiNN1/phpCacheAdmin/releases), unzip it into your web
directory and open it in a browser. No database and no Composer required.

If you use the defaults (e.g., Redis, Memcached servers), everything should work out of the box.
To customize the configuration, do not edit `config.dist.php` directly, but copy it into `config.php`.
Every option can also be set with [environment variables](#environment-variables) or an [.env file](#env-file).

It can also be embedded in your own website when installed via Composer, see [example_embedded_version.php](example_embedded_version.php).

### Access

There is no authentication until you define users in `authusers`([config](https://github.com/RobiNN1/phpCacheAdmin/blob/master/config.dist.php)),
so anyone who can open the page can also read, change and delete everything in the configured servers.
Keep it on a trusted network, or turn the login page on, or put your web server's own authentication in front of it.
Setting `readonly` on top of that leaves only the read-only parts of the dashboards.

## Cronjob

You can add these links to your cronjob to collect metrics when the dashboard is not open:

Redis `https://example.com/phpCacheAdmin/?dashboard=redis&server=0&ajax&metrics`

Memcached `https://example.com/phpCacheAdmin/?dashboard=memcached&server=0&ajax&metrics`

> `server=0` is the default server ID.

Metrics are collected whenever this link is refreshed, so you can set any time in the cronjob.

If you have authentication enabled, set `authtoken` in `config.php`
and append `&token=your-secret-token`to the cronjob URL so it can collect metrics without a login session.
It bypasses the login page, so create a long random one (`php -r 'echo bin2hex(random_bytes(32));'`) rather than picking something short.
The same token is also read from an `X-Pca-Token` header, which keeps it out of the access log:

```bash
curl -s -o /dev/null -H "X-Pca-Token: your-secret-token" "https://example.com/phpCacheAdmin/?dashboard=redis&server=0&ajax&metrics"
```

## Environment variables

All keys from the [config](https://github.com/RobiNN1/phpCacheAdmin/blob/master/config.dist.php) file are supported ENV variables, they just must start with `PCA_` prefix.
Array options use "_" e.g., PCA_REDIS_0_HOST, and values may also be JSON.

Redis:

- `PCA_REDISOPTIONS_CLIENT` `redis` or `predis`. Auto-detected when not set, set it to `predis` if the installed phpredis is older than 5.3.7.
- `PCA_REDISOPTIONS_BLOCKEDCOMMANDS` Extra commands to refuse in the console, as JSON `["CONFIG SET","SAVE"]` (optional).
- `PCA_REDISOPTIONS_PUBSUBREFRESH` Refresh interval for the Pub/Sub active channels list, in seconds (optional).
- `PCA_REDISOPTIONS_PUBSUBWINDOW` How long one Pub/Sub monitor request captures messages, in seconds, 1–10 (optional).
- `PCA_REDIS_0_NAME` The server name (optional).
- `PCA_REDIS_0_HOST` Optional when a path or nodes is specified.
- `PCA_REDIS_0_NODES` List of cluster nodes. You can set a value as JSON `["127.0.0.1:7000","127.0.0.1:7001","127.0.0.1:7002"]`.
- `PCA_REDIS_0_SENTINELS` List of Sentinels, replaces the host/port when set. You can set a value as JSON `["127.0.0.1:26379","127.0.0.1:26380","127.0.0.1:26381"]`.
- `PCA_REDIS_0_SENTINELMASTER` Name of the monitored master (optional). Default `mymaster`.
- `PCA_REDIS_0_SENTINELPASSWORD` Password of the Sentinels themselves, not of the master (optional).
- `PCA_REDIS_0_PORT` Optional when the default port is used.
- `PCA_REDIS_0_SCHEME` Connection scheme (optional). If you need a TLS connection, set it to `tls`.
- `PCA_REDIS_0_SSL` [SSL options](https://www.php.net/manual/en/context.ssl.php) for TLS. Requires Redis >= 6.0 (optional). You can set a value as JSON `{"cafile":"private.pem","verify_peer":true}`.
- `PCA_REDIS_0_DATABASE` Default database (optional).
- `PCA_REDIS_0_USERNAME` ACL - requires Redis >= 6.0 (optional).
- `PCA_REDIS_0_PASSWORD` Optional.
- `PCA_REDIS_0_AUTHFILE` File with a password, e.g., Docker secrets (optional).
- `PCA_REDIS_0_PATH` Unix domain socket (optional).
- `PCA_REDIS_0_DATABASES` Number of databases, use this if the CONFIG command is disabled (optional).
- `PCA_REDIS_0_SCANTHRESHOLD` Use SCAN automatically instead of KEYS when the database has more keys than this, 1000 keys are retrieved (optional). Default 100_000.
- `PCA_REDIS_0_SCANSIZE` Always use SCAN and retrieve at most this many keys, regardless of the threshold (optional).

Memcached:

- `PCA_MEMCACHEDOPTIONS_BLOCKEDCOMMANDS` Extra commands to refuse in the console, as JSON `["flush_all","stats reset"]` (optional).
- `PCA_MEMCACHED_0_NAME` The server name (optional).
- `PCA_MEMCACHED_0_HOST` Optional when a path is specified.
- `PCA_MEMCACHED_0_PORT` Optional when the default port is used.
- `PCA_MEMCACHED_0_PATH` Unix domain socket (optional).

APCu and OPcache:

- `PCA_APCU_SEPARATOR` Separator for the tree view (optional).
- `PCA_OPCACHE_WARMUPPATHS` Directories the warmup may compile from, as JSON `["/var/www"]`. Defaults to the document root.

Other:

- `PCA_AUTHUSERS` Users for the login page as JSON, e.g. `{"admin":"your-password"}`. Auth is off while it is empty. Passwords can be `password_hash()` hashes.
- `PCA_AUTHTOKEN` Token for the metrics cronjob, see [Cronjob](#cronjob).
- `PCA_CONSOLE` Set it to `false` to remove the consoles (optional).
- `PCA_READONLY` Set it to `true` to block every destructive action (optional).
- `PCA_SECURITYHEADERS` Set it to `false` to stop sending CSP and the other protection headers (optional).
- `PCA_DEBUG` Set it to `true` to show PHP and template errors on the page instead of only in the error log (optional).
- `PCA_PHP_MEMORY_LIMIT` In case you need to increase the PHP memory limit in Docker.
- `PCA_NGINX_PORT` In case you need to change the NGINX port in Docker.

Open the [config](https://github.com/RobiNN1/phpCacheAdmin/blob/master/config.dist.php) file for more info.

> To add another server, add the same environment variables, but change `0` to `1` (`2` for the third server and so on).

### .env file

You can keep these variables in a `.env` file instead of exporting them in the shell. This requires [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv):

```bash
composer require vlucas/phpdotenv
```

Copy [.env.example](https://github.com/RobiNN1/phpCacheAdmin/blob/master/.env.example) to `.env` and adjust the values.
It mirrors `config.dist.php`, so whatever is active there is active in it as well.

Real environment variables (e.g., set by Docker) always take precedence over the values in `.env`,
so you can still override anything at runtime.

## Docker

A Docker image is also available: https://hub.docker.com/r/robinn/phpcacheadmin

Run with a single command:

```bash
docker run -p 127.0.0.1:8080:80 -d --name phpcacheadmin -e "PCA_REDIS_0_HOST=redis_host" -e "PCA_REDIS_0_PORT=6379" -e "PCA_MEMCACHED_0_HOST=memcached_host" -e "PCA_MEMCACHED_0_PORT=11211" robinn/phpcacheadmin
```

Or use it in **docker-compose.yml**

```yaml
services:
  phpcacheadmin:
    image: robinn/phpcacheadmin
    ports:
      - "127.0.0.1:8080:80"
    #volumes:
    # If you want to use config.php instead of ENV variables
    #  - "./config.php:/var/www/html/config.php"
    environment:
      - PCA_REDIS_0_HOST=redis
      - PCA_REDIS_0_PORT=6379
      - PCA_MEMCACHED_0_HOST=memcached
      - PCA_MEMCACHED_0_PORT=11211
      - PCA_AUTHUSERS={"admin":"your-password"}
    depends_on:
      - redis
      - memcached
  redis:
    image: redis:8-alpine
  memcached:
    image: memcached:alpine
```

## Requirements

- PHP >= 8.2 (Use [v1 branch](https://github.com/RobiNN1/phpCacheAdmin/tree/v1.x) if you need support for >=7.4)
- Redis server >= 4.0, or Valkey / KeyDB
- phpredis >= 5.3.7 (the oldest build for PHP 8.2), otherwise set `client` in `redisoptions` to `predis` .
- Memcached server >= 1.4.31. SASL is not supported because there is no way to get the keys
- sqlite3 extension for metrics

> It is not necessary to have all dashboards enabled.

## Custom Dashboards

Here is an example of how to implement a custom dashboard

- [FileCache](https://github.com/RobiNN1/FileCache-Dashboard) ([`robinn/cache`](https://github.com/RobiNN1/Cache)) dashboard.

## Contributing

If you have a feature request, suggestion, or have found a bug,
please open an Issue describing what you would like to see.
AI tools are fine, but unchecked AI-generated code with irrelevant changes is not.
Discussing your ideas first saves everyone's time and prevents rejected contributions.

<!-- Font used in logo Arial Rounded MT Bold -->
