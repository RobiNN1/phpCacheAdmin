<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\KeyFilters;

trait APCuKeysList {
    private ?string $filter_error = null;

    /**
     * The search and filters from the URL.
     */
    public function keyFilters(): KeyFilters {
        return new KeyFilters(['nf', 'noexp', 'expin', 'idle', 'minsize', 'maxsize']);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    public function getAllKeys(): array {
        $filters = $this->keyFilters();
        $this->template->addGlobal('search_value', $filters->values['s']);

        // APCUIterator matches the keys itself.
        $entries = $this->entries($filters->pattern());
        $this->filter_error = $filters->error;

        if (!$filters->active()) {
            return is_array($entries) ? $entries : iterator_to_array($entries, false);
        }

        $now = time();
        $keys = [];

        foreach ($entries as $item) {
            $ttl = (int) ($item['ttl'] ?? 0);
            $meta = [
                'ttl'  => $ttl === 0 ? -1 : max(0, (int) ($item['creation_time'] ?? 0) + $ttl - $now),
                'size' => (int) ($item['mem_size'] ?? 0),
                'read' => (int) ($item['num_hits'] ?? 0) > 0,
                'idle' => $now - (int) ($item['access_time'] ?? $now),
            ];

            if ($filters->matches($meta)) {
                $keys[] = $item;
            }
        }

        return $keys;
    }

    /**
     * Delete every key that matches the search and filters.
     */
    private function deleteMatchingKeys(): string {
        if (!$this->keyFilters()->narrowed()) {
            return Helpers::alert('Set a search or a filter first, use "Delete all" to remove every key.', 'error');
        }

        $keys = $this->getAllKeys();

        if ($this->filter_error !== null) {
            return Helpers::alert($this->filter_error, 'error');
        }

        $deleted = 0;

        foreach ($keys as $item) {
            $deleted += apcu_delete((string) $item['key']) ? 1 : 0;
        }

        return Helpers::alert(sprintf('%s matching %s been deleted.', Format::number($deleted), $deleted === 1 ? 'key has' : 'keys have'), 'success');
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
        $separator = $this->separator();
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
