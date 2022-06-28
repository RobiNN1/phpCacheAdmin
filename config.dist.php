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
];
