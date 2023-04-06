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

namespace RobiNN\Pca\Dashboards\Memcached\Compatibility;

use RobiNN\Pca\Dashboards\Memcached\MemcachedException;

trait KeysTrait {
    use CommandTrait;

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
        $keys = [];

        $raw = $this->runCommand('lru_crawler metadump all');
        $lines = explode("\n", $raw);
        array_pop($lines);

        foreach ($lines as $line) {
            $keys[] = $this->keyData($line);
        }

        return $keys;
    }

    /**
     * Get raw key.
     *
     * @return string|false
     *
     * @throws MemcachedException
     */
    public function getKey(string $key) {
        $data = $this->runCommand('get '.$key, true);

        if (!isset($data[0])) {
            return false;
        }

        return !isset($data[1]) || $data[1] === 'N;' ? '' : $data[1];
    }

    /**
     * Check if key exists.
     *
     * @throws MemcachedException
     */
    public function exists(string $key): bool {
        return $this->getKey($key) !== false;
    }

    /**
     * Convert raw key line to an array.
     *
     * @return array<string, string|int>
     */
    private function keyData(string $line): array {
        static $data = [];

        foreach (explode(' ', $line) as $part) {
            if ($part !== '') {
                [$key, $val] = explode('=', $part);

                if ($key === 'exp') {
                    if ($val !== '-1') {
                        $val = (int) $val - time();
                    } else {
                        $val = (int) $val;
                    }
                }

                $data[$key] = $val;
            }
        }

        return $data;
    }
}
