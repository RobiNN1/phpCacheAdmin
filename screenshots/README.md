# Preview screenshots

Generates `assets/img/preview/{redis,memcached,opcache,apcu,realpath}-{light,dark}.webp`, 1920px wide
and at least 1040px tall, from a real phpCacheAdmin instance.

- `run.sh` copies the app to `/tmp/phpCacheAdmin`, serves it with `php -S`, seeds it and captures it.
- `seed.php` creates the demo data. It is requested over HTTP, because APCu, OPCache and the realpath
  cache live in the server process, not in a CLI process.
- `capture.mjs` drives headless Chromium and writes the webp files.

The dashboards default to the `system` theme, so light and dark come from Chromium's
`prefers-color-scheme`, no clicking needed. The lists that grow with the environment (OPCache scripts,
realpath entries) are requested with `pp=15`, everything else fits on one default page.

## CI

`.github/workflows/screenshots.yml` runs the same script with Redis and Memcached as services and
commits whatever changed as RobiNN1, back to the branch it ran on. It runs on a push to any branch
whose commit message contains `[screenshot]`:

```bash
git commit -m "Update preview section [screenshot]"
```

Without any change, an empty commit does the same:

```bash
git commit --allow-empty -m "Update screenshots [screenshot]"
```

The workflow lives only on the docs branch, so GitHub shows no "Run workflow" button for it, that
button reads the default branch.

## Locally

Needs php (apcu, opcache, redis), node, and a Redis and a Memcached server. **Both are flushed**, so
point the script at throwaway servers.

```bash
git clone https://github.com/RobiNN1/phpCacheAdmin.git screenshots/.app && ./screenshots/run.sh
```

`PCA_APP_DIR` uses an existing checkout instead, including its uncommitted changes, and
`PCA_REDIS_0_PORT` and `PCA_MEMCACHED_0_PORT` point at other servers:

```bash
PCA_APP_DIR=../phpCacheAdmin PCA_REDIS_0_PORT=6399 PCA_MEMCACHED_0_PORT=11299 ./screenshots/run.sh
```

| Variable               | Default                | Meaning                                        |
|------------------------|------------------------|------------------------------------------------|
| `PCA_APP_DIR`          | `screenshots/.app`     | phpCacheAdmin checkout                         |
| `PCA_DOCROOT`          | `/tmp/phpCacheAdmin`   | where the copy of the app is served from       |
| `PCA_PORT`             | `8123`                 | port of the built-in server                    |
| `PCA_WARMUP`           | `5`                    | requests per dashboard before capturing        |
| `PCA_REDIS_0_HOST`     | `127.0.0.1`            |                                                |
| `PCA_REDIS_0_PORT`     | `6379`                 |                                                |
| `PCA_MEMCACHED_0_HOST` | `127.0.0.1`            |                                                |
| `PCA_MEMCACHED_0_PORT` | `11211`                |                                                |
| `PCA_WIDTH`            | `1920`                 | screenshot width                               |
| `PCA_MIN_HEIGHT`       | `1040`                 | minimum screenshot height                      |
| `PCA_QUALITY`          | `82`                   | webp quality                                   |
