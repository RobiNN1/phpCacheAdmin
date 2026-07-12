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
     * A key can disappear between the listing and the pipeline call, so fall back to safe defaults.
     *
     * @param array<int|string, mixed> $pipeline
     *
     * @return array<string, string|int>
     */
    private function keyInfo(array $pipeline, string $key): array {
        $data = $pipeline[$key] ?? [];
        $ttl = $data['ttl'] ?? -1;

        return [
            'bytes_size' => $data['size'] ?? 0,
            'type'       => $data['type'] ?? 'unknown',
            'ttl'        => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
        ];
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
                'info'  => ['link_title' => $key] + $this->keyInfo($pipeline, $key),
            ];
        }

        return Helpers::sortKeys($formatted_keys);
    }

    /**
     * @param array<int|string, mixed> $keys_array
     *
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    public function keysTreeView(array $keys_array): array {
        $pipeline = $this->pipeline($keys_array);
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        $this->template->addGlobal('separator', $separator);

        $keys = [];

        foreach ($keys_array as $key) {
            $keys[] = [
                'key'   => $key,
                'items' => $pipeline[$key]['count'] ?? null,
                'info'  => $this->keyInfo($pipeline, $key),
            ];
        }

        return Helpers::keysTree($keys, $separator);
    }
}
