<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait MemcachedKeysList {
    /**
     * @return array<int, string>
     *
     * @throws MemcachedException
     */
    public function getAllKeys(): array {
        $search = Http::get('s', '');
        $this->template->addGlobal('search_value', $search);

        $all_key_lines = $this->memcached->getKeys();

        if ($search === '') {
            return $all_key_lines;
        }

        $filtered_lines = [];

        foreach ($all_key_lines as $line) {
            $space = strpos($line, ' ');
            $key = substr($line, 4, ($space === false ? strlen($line) : $space) - 4);

            if (str_contains($key, '%')) {
                $key = urldecode($key);
            }

            if (stripos($key, $search) !== false) {
                $filtered_lines[] = $line;
            }
        }

        return $filtered_lines;
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<int, array<string, mixed>>
     */
    public function keysTableView(array $raw_lines): array {
        $formatted_keys = [];
        $time = time();

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);
            $ttl = $key_data['exp'] ?? null;
            $ttl_display = $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time;

            $formatted_keys[] = [
                'key'  => $key_data['key'],
                'info' => [
                    'link_title'           => urldecode($key_data['key']),
                    'bytes_size'           => $key_data['size'] ?? 0,
                    'timediff_last_access' => $key_data['la'] ?? 0,
                    'ttl'                  => $ttl_display,
                ],
            ];
        }

        return Helpers::sortKeys($this->template, $formatted_keys);
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<string, mixed>
     * @throws MemcachedException
     */
    public function keysTreeView(array $raw_lines): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        if (version_compare($this->memcached->version(), '1.5.19', '>=')) {
            $separator = urlencode($separator);
        }

        $this->template->addGlobal('separator', urldecode($separator));

        $time = time();

        $tree = [];

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);

            if (!isset($key_data['key'])) {
                continue;
            }

            $ttl = $key_data['exp'] ?? null;
            $ttl_display = $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time;

            $parts = explode($separator, $key_data['key']);

            /** @var array<int|string, mixed> $current */
            $current = &$tree;
            $path = '';

            foreach ($parts as $i => $part) {
                $path = $path !== '' && $path !== '0' ? $path.$separator.$part : $part;

                if ($i === count($parts) - 1) { // check last part
                    $current[] = [
                        'type' => 'key',
                        'name' => urldecode($part),
                        'key'  => $key_data['key'],
                        'info' => [
                            'bytes_size'           => $key_data['size'] ?? 0,
                            'timediff_last_access' => $key_data['la'] ?? 0,
                            'ttl'                  => $ttl_display,
                        ],
                    ];
                } else {
                    if (!isset($current[$part])) {
                        $current[$part] = [
                            'type'     => 'folder',
                            'name'     => $part,
                            'path'     => $path,
                            'children' => [],
                            'expanded' => false,
                        ];
                    }

                    $current = &$current[$part]['children'];
                }
            }
        }

        Helpers::countChildren($tree);

        return $tree;
    }
}
