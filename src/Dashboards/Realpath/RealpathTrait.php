<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
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
        $total_memory = Format::iniSizeToBytes(ini_get('realpath_cache_size'));
        $memory_used = realpath_cache_size();
        $memory_usage_percentage = round(($memory_used / $total_memory) * 100, 2);

        $panels = [
            [
                'title' => 'Realpath info',
                'data'  => [
                    'Total' => Format::bytes($total_memory, 0),
                    'Used'  => Format::bytes($memory_used).' ('.$memory_usage_percentage.'%)',
                ],
            ],
            [
                'title' => 'Keys',
                'data'  => [
                    'TTL'    => ini_get('realpath_cache_ttl'),
                    'Cached' => Format::number(count($all_keys)),
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
