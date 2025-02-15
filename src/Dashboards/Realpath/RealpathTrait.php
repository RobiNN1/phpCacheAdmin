<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Realpath;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait RealpathTrait {
    private function panels(): string {
        $total_memory = Format::iniSizeToBytes(ini_get('realpath_cache_size'));
        $memory_used = realpath_cache_size();
        $memory_usage = round(($memory_used / $total_memory) * 100, 2);

        $panels = [
            [
                'title' => 'Realpath info',
                'data'  => [
                    'Total' => Format::bytes($total_memory, 0),
                    ['Used', Format::bytes($memory_used).' ('.$memory_usage.'%)', $memory_usage],
                ],
            ],
            [
                'title' => 'Keys',
                'data'  => [
                    'TTL'    => ini_get('realpath_cache_ttl'),
                    'Cached' => Format::number(count($this->all_keys)),
                ],
            ],
        ];

        return $this->template->render('partials/info', ['panels' => $panels]);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function getAllKeys(): array {
        static $keys = [];
        $search = Http::get('s', '');

        $this->template->addGlobal('search_value', $search);

        foreach ($this->all_keys as $key_name => $key_data) {
            if (stripos($key_name, $search) !== false) {
                $keys[] = [
                    'key'  => $key_name,
                    'info' => [
                        'title'    => $key_name,
                        'realpath' => $key_data['realpath'],
                        'is_dir'   => $key_data['is_dir'] ? 'true' : 'false',
                        'ttl'      => $key_data['expires'] - time(),
                    ],
                ];
            }
        }

        return Helpers::sortKeys($this->template, $keys);
    }
}
