<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached\Compatibility;

use RobiNN\Pca\Dashboards\Memcached\MemcachedException;

trait MemcachedKeys {
    use RunCommand;

    /**
     * Get all the keys.
     *
     * Note: `getAllKeys()` or `stats cachedump` based functions do not work
     * properly, and this is currently the best way to retrieve all keys.
     *
     * This command requires Memcached server >= 1.4.31
     *
     * @link https://github.com/memcached/memcached/wiki/ReleaseNotes1431
     *
     * @return array<int, mixed>
     *
     * @throws MemcachedException
     */
    public function getKeys(): array {
        $raw = $this->runCommand('lru_crawler metadump all');
        $lines = array_filter(explode("\n", trim($raw)), static fn ($line): bool => !empty($line) && $line !== 'END');
        $keys = [];

        foreach ($lines as $line) {
            $keys[] = $this->parseLine($line);
        }

        return $keys;
    }

    /**
     * Convert raw key line to an array.
     *
     * @return array<string, string|int>
     */
    private function parseLine(string $line): array {
        $data = [];

        foreach (explode(' ', $line) as $part) {
            if ($part !== '') {
                [$key, $val] = explode('=', $part);
                $data[$key] = is_numeric($val) ? (int) $val : $val;
            }
        }

        return $data;
    }

    /**
     * Get raw key.
     *
     * @throws MemcachedException
     */
    public function getKey(string $key): string|false {
        $raw = $this->runCommand('get '.$key);
        $data = explode("\r\n", $raw);

        if ($data[0] === 'END') {
            return false;
        }

        return !isset($data[1]) || $data[1] === 'N;' ? '' : $data[1];
    }

    /**
     * Check if the key exists.
     *
     * @throws MemcachedException
     */
    public function exists(string $key): bool {
        return $this->getKey($key) !== false;
    }
}
