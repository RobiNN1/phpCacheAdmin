<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Config;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\KeyFilters;

trait RedisKeysList {
    private ?string $filter_error = null;

    /**
     * With SCAN, the list stops at the scan size, so it can show only the first matches.
     */
    private bool $scan_partial = false;

    /**
     * The search and filters from the URL.
     */
    public function keyFilters(): KeyFilters {
        $types = ['string', 'hash', 'list', 'set', 'zset', 'stream', 'json', 'vectorset'];

        return new KeyFilters(['type', 'noexp', 'expin', 'minsize', 'maxsize'], array_combine($types, $types));
    }

    /**
     * @param bool $all Ignore the scan limit (export and deleting the matching keys must not be truncated).
     *
     * @return array<int, string>
     *
     * @throws Exception
     */
    public function getAllKeys(bool $all = false): array {
        $filters = $this->keyFilters();
        $search = $filters->values['s'];
        $this->template->addGlobal('search_value', $search);

        $mode = $filters->values['match'];
        $pattern = match ($mode) {
            'prefix' => $search === '' ? '*' : $this->escapeGlob($search).'*',
            'regex' => '*', // matched below, Redis patterns are globs
            default => $this->searchPattern($search),
        };

        $matcher = $mode === 'regex' ? $filters->matcher() : null;
        $this->filter_error = $filters->error;
        $this->scan_partial = false;

        if (!$this->usesScan()) {
            return $this->filterKeys($this->redis->keys($pattern), $matcher, $filters);
        }

        $scansize = (int) (Config::get('redisoptions.scansize') ?? 1000);
        $limit = $all ? PHP_INT_MAX : $scansize;

        if ($matcher === null && !$filters->active()) {
            $keys = $this->redis->scanKeys($pattern, $scansize, $limit);
            $this->scan_partial = count($keys) >= $limit;

            return $keys;
        }

        // The filters are applied to each batch, so the limit counts matching keys, not scanned ones.
        $keys = [];

        foreach ($this->redis->scanBatches($pattern, $scansize, $this->scanType($filters->values['type'])) as $batch) {
            array_push($keys, ...$this->filterKeys($batch, $matcher, $filters));

            if (count($keys) >= $limit) {
                $this->scan_partial = true;

                return array_slice($keys, 0, $limit);
            }
        }

        return $keys;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<int, string>
     *
     * @throws Exception
     */
    private function filterKeys(array $keys, ?callable $matcher, KeyFilters $filters): array {
        if ($matcher !== null) {
            $keys = array_values(array_filter($keys, $matcher));
        }

        if ($keys === [] || !$filters->active()) {
            return $keys;
        }

        // TTL, type and size come from the same script as the key list columns, in chunks.
        $meta = $this->pipeline($keys);

        return array_values(array_filter($keys, static function (string $key) use ($meta, $filters): bool {
            if (!isset($meta[$key])) {
                return false; // removed since it was listed
            }

            return $filters->matches([
                'ttl'  => (int) $meta[$key]['ttl'],
                'type' => (string) $meta[$key]['type'],
                'size' => (int) $meta[$key]['size'],
            ]);
        }));
    }

    /**
     * @throws Exception
     */
    private function usesScan(): bool {
        $scan_threshold = Config::get('redisoptions.scanthreshold', 100_000);

        return Config::get('redisoptions.scansize') !== null || $this->redis->databaseSize() > $scan_threshold || !$this->isCommandSupported('KEYS');
    }

    /**
     * The type for SCAN TYPE (Redis >= 6.0), the filter checks it again, a cluster ignores it.
     */
    private function scanType(string $type): ?string {
        if ($type === '') {
            return null;
        }

        try {
            $version = (string) ($this->redis->getInfo('server')['redis_version'] ?? '0');
        } catch (Exception) {
            return null;
        }

        if (version_compare($version, '6.0.0', '<')) {
            return null;
        }

        return $type === 'json' ? 'ReJSON-RL' : $type;
    }

    private function escapeGlob(string $value): string {
        return preg_replace('/([*?\[\]\\\\])/', '\\\\$1', $value);
    }

    /**
     * Delete every key that matches the search and filters.
     *
     * @throws Exception
     */
    private function deleteMatchingKeys(): string {
        if (!$this->keyFilters()->narrowed()) {
            return Helpers::alert('Set a search or a filter first, use "Delete all" to remove every key.', 'error');
        }

        $keys = $this->getAllKeys(true);

        if ($this->filter_error !== null) {
            return Helpers::alert($this->filter_error, 'error');
        }

        // One key per call, several keys of a cluster can live in different slots.
        foreach ($keys as $key) {
            $this->redis->del($key);
        }

        $count = count($keys);

        return Helpers::alert(sprintf('%s matching %s been deleted.', Format::number($count), $count === 1 ? 'key has' : 'keys have'), 'success');
    }

    private function searchPattern(string $search): string {
        $search = trim($search);

        if ($search === '') {
            return '*';
        }

        return preg_match('/[*?\[\]]/', $search) === 1 ? $search : '*'.$search.'*';
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
