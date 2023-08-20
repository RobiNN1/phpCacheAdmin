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

namespace RobiNN\Pca\Dashboards\Realpath;

use RobiNN\Pca\Format;
use RobiNN\Pca\Http;

trait RealpathTrait {
    /**
     * @param array<string, mixed> $all_keys
     */
    private function panels(array $all_keys): string {
        $panels = [
            [
                'title' => 'Realpath Info',
                'data'  => [
                    'Total'      => ini_get('realpath_cache_size'),
                    'Used'       => Format::bytes(realpath_cache_size()),
                    'Cache keys' => Format::number(count($all_keys)),
                ],
            ],
        ];

        return $this->template->render('partials/info', ['panels' => $panels]);
    }

    /**
     * @param array<string, mixed> $all_keys
     *
     * @return array<int, array<string, string|int>>
     */
    private function getAllKeys(array $all_keys): array {
        static $keys = [];
        $search = Http::get('s', '');

        $this->template->addGlobal('search_value', $search);

        foreach ($all_keys as $key_name => $key_data) {
            if ($search === '' || stripos($key_name, $search) !== false) {
                $keys[] = [
                    'key'   => $key_name,
                    'items' => [
                        'title'    => $key_name,
                        'realpath' => $key_data['realpath'],
                        'is_dir'   => $key_data['is_dir'] ? 'true' : 'false',
                        'ttl'      => $key_data['expires'] - time(),
                    ],
                ];
            }
        }

        return $keys;
    }
}
