<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait RedisKeysList {
    /**
     * @return array<int, string>
     *
     * @throws Exception
     */
    public function getAllKeys(): array {
        $filter = Http::get('s', '*');
        $this->template->addGlobal('search_value', $filter);

        $scansize = $this->servers[$this->current_server]['scansize'] ?? null;
        $scan_threshold = $this->servers[$this->current_server]['scanthreshold'] ?? 100_000;

        if ($scansize !== null || $this->redis->databaseSize() > $scan_threshold || !$this->isCommandSupported('KEYS')) {
            return $this->redis->scanKeys($filter, (int) ($scansize ?? 1000));
        }

        return $this->redis->keys($filter);
    }

    public function isCommandSupported(string $command): bool {
        static $supported = [];

        try {
            return $supported[$command] ??= $this->redis->commandExists(strtolower($command));
        } catch (Exception) {
            return false;
        }
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    private function pipeline(array $keys): array {
        $data = [];

        foreach (array_chunk(array_values($keys), 1000) as $chunk) {
            $data += $this->redis->pipelineKeys($chunk);
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $keys_array
     *
     * @return array<int, array<string, string|int>>
     *
     * @throws Exception
     */
    public function keysTableView(array $keys_array): array {
        $pipeline = $this->pipeline($keys_array);
        $formatted_keys = [];

        foreach ($keys_array as $key) {
            $formatted_keys[] = [
                'key'   => $key,
                'items' => $pipeline[$key]['count'] ?? null,
                'info'  => [
                    'link_title' => $key,
                    'bytes_size' => $pipeline[$key]['size'],
                    'type'       => $pipeline[$key]['type'],
                    'ttl'        => $pipeline[$key]['ttl'] === -1 ? 'Doesn\'t expire' : $pipeline[$key]['ttl'],
                ],
            ];
        }

        return Helpers::sortKeys($this->template, $formatted_keys);
    }

    /**
     * @param array<int|string, mixed> $keys_array
     *
     * @return array<int, array<string, string|int>>
     *
     * @throws Exception
     */
    public function keysTreeView(array $keys_array): array {
        $pipeline = $this->pipeline($keys_array);
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        $this->template->addGlobal('separator', $separator);

        $tree = [];

        foreach ($keys_array as $key) {
            $parts = explode($separator, $key);
            /** @var array<int|string, mixed> $current */
            $current = &$tree;
            $path = '';

            foreach ($parts as $i => $part) {
                $path = $path !== '' && $path !== '0' ? $path.$separator.$part : $part;

                if ($i === count($parts) - 1) { // check last part
                    $current[] = [
                        'type'  => 'key',
                        'name'  => $part,
                        'key'   => $key,
                        'items' => $pipeline[$key]['count'] ?? null,
                        'info'  => [
                            'bytes_size' => $pipeline[$key]['size'],
                            'type'       => $pipeline[$key]['type'],
                            'ttl'        => $pipeline[$key]['ttl'] === -1 ? 'Doesn\'t expire' : $pipeline[$key]['ttl'],
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
