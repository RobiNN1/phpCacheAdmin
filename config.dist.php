<?php
/**
 * This file is part of phpCacheAdmin.
 *
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

return [
    // The order of the items also changes the position of the sidebar links.
    // You can comment out any item except ServerDashboard because it's default dashboard.
    'dashboards' => [
        RobiNN\Pca\Dashboards\Server\ServerDashboard::class,
        RobiNN\Pca\Dashboards\Redis\RedisDashboard::class,
        RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard::class,
        RobiNN\Pca\Dashboards\OPCache\OPCacheDashboard::class,
        RobiNN\Pca\Dashboards\APCu\APCuDashboard::class,
    ],
    'redis'      => [
        [
            'name' => 'Localhost', // Optional
            'host' => '127.0.0.1',
            'port' => 6379, // Optional
            //'database' => 0, // Optional
            //'password' => '', // Optional
        ],
    ],
    'memcached'  => [
        [
            'name' => 'Localhost', // Optional
            'host' => '127.0.0.1',
            'port' => 11211, // Optional
        ],
    ],
    'timeformat' => 'd. m. Y H:i:s',
    'twigdebug'  => false,
    // Auth function, this is an example with http auth, but you can add own logic
    /*'auth'       => static function (): void {
        $username = 'admin';
        $password = 'pass';

        if (
            !isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] !== $username || $_SERVER['PHP_AUTH_PW'] !== $password
        ) {
            header('WWW-Authenticate: Basic realm="phpCacheAdmin Login"');
            header('HTTP/1.0 401 Unauthorized');

            echo 'Incorrect username or password!';
            exit();
        }

        if (isset($_GET['logout'])) {
            $is_https = (
                (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1)) ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            );

            header('Location: http'.($is_https ? 's' : '').'://reset:reset@'.($_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']));
        }
    },*/
    // Decoding/Encoding functions
    'encoding'   => [
        'gzcompress' => [
            'view' => static fn (string $value): ?string => @gzuncompress($value) !== false ? gzuncompress($value) : null,
            'save' => static fn (string $value): string => gzcompress($value),
        ],
        'gzencode'   => [
            'view' => static fn (string $value): ?string => @gzdecode($value) !== false ? gzdecode($value) : null,
            'save' => static fn (string $value): string => gzencode($value),
        ],
        'gzdeflate'  => [
            'view' => static fn (string $value): ?string => @gzinflate($value) !== false ? gzinflate($value) : null,
            'save' => static fn (string $value): string => gzdeflate($value),
        ],
    ],
    // Formatting functions, it runs after decoding
    'formatters' => [
        static function (string $value): ?string {
            if (@unserialize($value, ['allowed_classes' => false]) !== false) {
                $unserialized_value = unserialize($value, ['allowed_classes' => false]);

                if (is_array($unserialized_value)) {
                    try {
                        $unserialized_value = json_encode($unserialized_value, JSON_THROW_ON_ERROR);
                    } catch (Exception $e) {
                        // ...
                    }
                }

                return (string) $unserialized_value;
            }

            return null;
        },
    ],
];
