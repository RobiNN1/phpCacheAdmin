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
    'redis'       => [
        [
            'name' => 'Localhost', // Optional
            'host' => '127.0.0.1',
            'port' => 6379, // Optional
            //'database' => 0, // Optional
            //'password' => '', // Optional
        ],
        /*[
            'name'     => 'Docker',
            'host'     => '127.0.0.1',
            'port'     => 49153,
            'password' => 'redispw',
        ],*/
    ],
    'memcached'   => [
        [
            'name' => 'Localhost', // Optional
            'host' => '127.0.0.1',
            'port' => 11211, // Optional
        ],
        /*[
            'name' => 'Docker',
            'host' => '127.0.0.1',
            'port' => 49154,
        ],*/
    ],
    'time_format' => 'd. m. Y H:i:s',
    'twig_debug'  => false,
];
