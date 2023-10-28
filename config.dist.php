<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

return [
    // The order of the items also changes the position of the
    // sidebar links, first item is also the default dashboard.
    // You can comment out (or delete) any dashboard.
    'dashboards'    => [
        RobiNN\Pca\Dashboards\Server\ServerDashboard::class,
        RobiNN\Pca\Dashboards\Redis\RedisDashboard::class,
        RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard::class,
        RobiNN\Pca\Dashboards\OPCache\OPCacheDashboard::class,
        RobiNN\Pca\Dashboards\APCu\APCuDashboard::class,
        RobiNN\Pca\Dashboards\Realpath\RealpathDashboard::class,
    ],
    'redis'         => [
        [
            'name' => 'Localhost', // The server name (optional).
            'host' => '127.0.0.1', // Optional when a path is specified.
            'port' => 6379, // Optional when the default port is used.
            //'scheme'    => 'tls', // Connection scheme (optional).
            //'ssl'       => [], // SSL options for TLS https://www.php.net/manual/en/context.ssl.php - requires Redis >= 6.0 (optional).
            //'database'  => 0, // Default database (optional).
            //'username'  => '', // ACL - requires Redis >= 6.0 (optional).
            //'password'  => '', // Optional.
            //'authfile'  => '/run/secrets/file_name', // File with a password, e.g. Docker secrets (optional).
            //'path'      => '/var/run/redis/redis-server.sock', // Unix domain socket (optional).
            //'databases' => 16, // Number of databases, use this if the CONFIG command is disabled (optional).
            //'scansize'  => 1000, // Number of keys, the server will use the SCAN command instead of KEYS (optional).
        ],
    ],
    'memcached'     => [
        [
            'name' => 'Localhost', // The server name, optional.
            'host' => '127.0.0.1', // Optional when a path is specified.
            'port' => 11211, // Optional when the default port is used.
            //'path' => '/var/run/memcached/memcached.sock', // Unix domain socket (optional).
        ],
    ],
    // Example of authentication with http auth. Uncomment to enable it.
    /*'auth'          => static function (): void {
        $username = 'admin';
        $password = 'pass';

        if (
            !isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] !== $username || $_SERVER['PHP_AUTH_PW'] !== $password
        ) {
            header('WWW-Authenticate: Basic realm="phpCacheAdmin Login"');
            header('HTTP/1.0 401 Unauthorized');

            exit('Incorrect username or password!');
        }

        // Use this section for the logout. It will display a link in the sidebar.
        if (isset($_GET['logout'])) {
            $is_https = (
                (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1)) ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            );

            header('Location: http'.($is_https ? 's' : '').'://reset:reset@'.($_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']));
        }
    },*/
    // Decoding / Encoding functions
    'converters'    => [
        'gzcompress' => [
            'view' => static fn (string $value): ?string => extension_loaded('zlib') && @gzuncompress($value) !== false ? gzuncompress($value) : null,
            'save' => static fn (string $value): string => extension_loaded('zlib') ? gzcompress($value) : $value,
        ],
        'gzencode'   => [
            'view' => static fn (string $value): ?string => extension_loaded('zlib') && @gzdecode($value) !== false ? gzdecode($value) : null,
            'save' => static fn (string $value): string => extension_loaded('zlib') ? gzencode($value) : $value,
        ],
        'gzdeflate'  => [
            'view' => static fn (string $value): ?string => extension_loaded('zlib') && @gzinflate($value) !== false ? gzinflate($value) : null,
            'save' => static fn (string $value): string => extension_loaded('zlib') ? gzdeflate($value) : $value,
        ],
        /*'gz_magento' => [
            'view' => static function (string $value): ?string {
                // https://github.com/colinmollenhour/Cm_Cache_Backend_Redis/blob/master/Cm/Cache/Backend/Redis.php#L1307-L1328
                $value = str_starts_with($value, "gz:\x1f\x8b") ? (string) substr($value, 5) : $value;

                return extension_loaded('zlib') && @gzuncompress($value) !== false ? gzuncompress($value) : null;
            },
            'save' => static fn (string $value): string => extension_loaded('zlib') ? "gz:\x1f\x8b".gzcompress($value) : $value,
        ],*/
    ],
    // Formatting functions, it runs after decoding
    'formatters'    => [
        'unserialize' => static function (string $value): ?string {
            $unserialized_value = @unserialize($value, ['allowed_classes' => false]);
            if ($unserialized_value !== false && is_array($unserialized_value)) {
                try {
                    return json_encode($unserialized_value, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    return null;
                }
            }

            return null;
        },
    ],
    // Customizations
    //'timezone'      => 'Europe/Bratislava', // Leave empty (or commented out) to get it automatically obtained.
    'time-format'   => 'd. m. Y H:i:s',
    'decimal-sep'   => ',',
    'thousands-sep' => ' ',
];
