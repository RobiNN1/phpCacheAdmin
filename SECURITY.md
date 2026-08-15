# Security Policy

## Supported versions

Only the latest release gets security fixes. Older versions are not patched, upgrade first and check whether the issue still exists.

## What phpCacheAdmin is

phpCacheAdmin is an administration tool for cache servers that normally sit next to the application, usually on
localhost. It is configured with the credentials of those servers, so anyone who can open the page can do to the cache
whatever the server itself allows. That is the point of the tool, not a defect in it.

### Authentication is off by default

There is no login page until you define users in `authusers`. This is deliberate: most installations run it behind
something that already handles access – a reverse proxy with its own authentication, the web server's `.htpasswd`, a
VPN, an SSH tunnel, or a private network - and forcing a second login on top of that only gets in the way.

Whether the dashboard is reachable by anyone else is therefore a deployment decision. Options that ship with it:

- `authusers` - the built-in login page. Passwords can be `password_hash()` hashes.
- `readonly` - blocks every destructive action and removes the consoles. See below for what it covers.
- `console` - set it to `false` to remove the Redis and Memcached consoles on their own.
- `redisoptions.blockedcommands` / `memcachedoptions.blockedcommands` - extra commands to refuse in the console.

### What read-only mode covers

`readonly` protects stored data – the keys and values in Redis, Memcached and APCu. Nothing that would write, edit,
delete or import a key gets through, and the consoles are removed with it.

It deliberately does not apply to the OPcache and Realpath dashboards. There is nothing to lose there: both hold a
cache of what PHP already derived from files on disk, so clearing it only costs the recompiling or the stat call that
fills it again on the next request.

So "read-only mode did not stop me from resetting OPcache" is the documented behaviour, not a bypass.

### The consoles

The consoles exist to run commands against the server, so most of what they can do is by design – the same as the SQL
tab of a database tool. Commands that execute code on the server (`EVAL` and the rest of the Lua and functions family,
`MODULE LOAD`) are always refused because those turn cache access into code execution on the host. Anything else can be
refused with `blockedcommands` in `redisoptions` / `memcachedoptions`, and the whole feature can be removed with
`console => false`.

## Reporting a vulnerability

Open an [issue](https://github.com/RobiNN1/phpCacheAdmin/issues), or write to robo@kelcak.com if you would rather
discuss it privately first. Include the version, the configuration it happens with, and what an attacker gains.

### In scope

- Bypassing the login page while `authusers` is set, or an action that changes cached data while `readonly` is on.
- CSRF, XSS (including from a value stored in the cache), or anything that lets one dashboard user act as another.
- Path traversal, arbitrary file read or write, code execution that does not require console access.
- Leaking the configured server credentials to someone who cannot already read them.

### Not in scope

- No authentication in the default configuration, or a dashboard exposed to a network without it. See above.
- Clearing the OPcache or Realpath cache, or resetting a slow log or latency monitor, while `readonly` is on. See above.
- Commands run in the console, or the data the dashboard can reach on servers it is configured for. Use `readonly`,
  `console => false` or `blockedcommands` if the people with access should not have that.
- Weaknesses of the cache server itself, e.g., a Redis that allows `CONFIG SET dir`, runs as root, or has
  `enable-module-command` turned on. Report those to the server's maintainers.
- Findings from a scanner with no working proof, and reports about dependencies that are only used for development.
