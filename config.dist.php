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
    // You can comment out any item except ServerDashboard becouse it's default dashboard.
    'dashboards' => [
        RobiNN\Pca\Dashboards\Server\ServerDashboard::class,
        RobiNN\Pca\Dashboards\Redis\RedisDashboard::class,
        RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard::class,
        RobiNN\Pca\Dashboards\OPCache\OPCacheDashboard::class,
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
    // Decoding functions
    'decoders'   => [
        static fn (string $value): ?string => @gzuncompress($value) !== false ? gzuncompress($value) : null,
        static fn (string $value): ?string => @gzdecode($value) !== false ? gzdecode($value) : null,
        static fn (string $value): ?string => @gzinflate($value) !== false ? gzinflate($value) : null,
        static function (string $value): ?string { // Magento
            // https://github.com/colinmollenhour/Cm_Cache_Backend_Redis/blob/master/Cm/Cache/Backend/Redis.php#L1306-L1323
            if (strpos($value, "gz:\x1f\x8b") === 0) {
                $value = substr($value, 5);
            }

            return @gzuncompress($value) !== false ? gzuncompress($value) : null;
        },
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

                return $unserialized_value;
            }

            return null;
        },
    ],
];
