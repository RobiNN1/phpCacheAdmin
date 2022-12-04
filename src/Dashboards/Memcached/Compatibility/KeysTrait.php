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
use RobiNN\Pca\Helpers;

trait KeysTrait {
    use CommandTrait;

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

    /**
     * Get all keys.
     *
     * This command requires Memcached server >= 1.4.18
     *
     * @link https://github.com/memcached/memcached/wiki/ReleaseNotes1418#lru-crawler
     *
     * @return array<int, mixed>
     * @throws MemcachedException
     */
    public function getKeys(): array {
        static $keys = [];

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
     * @throws MemcachedException
     */
    public function getKey(string $key) {
        $raw = $this->runCommand('get '.$key);
        $lines = explode("\r\n", $raw);

        if (Helpers::str_starts_with($raw, 'VALUE') && Helpers::str_ends_with($raw, 'END')) {
            return !isset($lines[1]) || $lines[1] === 'N;' ? '' : $lines[1];
        }

        return false;
    }

    /**
     * Check if key exists.
     *
     * @throws MemcachedException
     */
    public function exists(string $key): bool {
        return $this->getKey($key) !== false;
    }
}
