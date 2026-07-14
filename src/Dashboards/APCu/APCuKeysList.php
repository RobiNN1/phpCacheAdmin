<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use APCUIterator;
use RobiNN\Pca\Config;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait APCuKeysList {
    /**
     * @return array<int, array<string, string|int>>
     */
    public function getAllKeys(): array {
        $search = Http::get('s', '');
        $this->template->addGlobal('search_value', $search);

        $keys = [];

        $fields = APC_ITER_KEY | APC_ITER_TTL | APC_ITER_MEM_SIZE | APC_ITER_NUM_HITS | APC_ITER_ATIME | APC_ITER_CTIME;
        $iterator = new APCUIterator(null, $fields, 0, APC_LIST_ACTIVE);

        foreach ($iterator as $item) {
            if ($search !== '' && stripos($item['key'], $search) === false) {
                continue;
            }

            $keys[] = $item;
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, string|int>
     */
    private function keyInfo(array $item): array {
        $ttl = $item['ttl'] ?? 0;

        return [
            'bytes_size'         => $item['mem_size'] ?? 0,
            'number_hits'        => $item['num_hits'] ?? 0,
            'timediff_last_used' => $item['access_time'] ?? 0,
            'time_created'       => $item['creation_time'] ?? 0,
            'ttl'                => $ttl === 0 ? 'Doesn\'t expire' : (($item['creation_time'] ?? 0) + $ttl - time()),
        ];
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int, array<string, string|int>>
     */
    public function keysTableView(array $keys): array {
        $formatted_keys = [];

        foreach ($keys as $key_data) {
            $formatted_keys[] = [
                'key'  => $key_data['key'],
                'info' => ['link_title' => $key_data['key']] + $this->keyInfo($key_data),
            ];
        }

        return Helpers::sortKeys($formatted_keys);
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int|string, mixed>
     */
    public function keysTreeView(array $keys): array {
        $separator = Config::get('apcuseparator', ':');
        $this->template->addGlobal('separator', $separator);

        $tree_keys = [];

        foreach ($keys as $key_data) {
            $tree_keys[] = [
                'key'  => $key_data['key'],
                'info' => $this->keyInfo($key_data),
            ];
        }

        return Helpers::keysTree($tree_keys, $separator);
    }
}
