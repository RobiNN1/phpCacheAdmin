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

namespace RobiNN\Pca\Dashboards\Memcached\MemcacheCompatibility;

trait RunCommandTrait {
    /**
     * Run command.
     *
     * https://github.com/memcached/memcached/wiki/Commands
     *
     * @param string $command
     *
     * @return ?array<int, mixed>
     */
    public function runCommand(string $command): ?array {
        $data = [];

        $this->server['port'] ??= 11211;

        $fp = @fsockopen($this->server['host'], (int) $this->server['port'], $error_code, $error_message, 3);

        if ($fp === false) {
            return null;
        }

        fwrite($fp, $command."\n");

        $part = '';

        while (true) {
            $part .= fgets($fp, 1024);
            $lines = explode("\n", $part);
            $part = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === 'END' || $line === 'ERROR' || $line === '') {
                    break 2;
                }

                $data[] = $line;
            }
        }

        fclose($fp);

        return $data;
    }

    /**
     * Get data from line.
     *
     * @param string $line
     *
     * @return array<string, string|int>
     */
    private function keyData(string $line): array {
        static $data = [];

        foreach (explode(' ', $line) as $part) {
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

        return $data;
    }

    /**
     * Get all keys.
     *
     * @return array<int, mixed>
     */
    public function getKeys(): array {
        static $keys = [];

        $all_keys = $this->runCommand('lru_crawler metadump all');

        if ($all_keys !== null) {
            foreach ($all_keys as $line) {
                $keys[] = $this->keyData($line);
            }
        }

        return $keys;
    }

    /**
     * Get original key.
     *
     * @param string $key
     *
     * @return string|false
     */
    public function getKey(string $key) {
        $data = $this->runCommand('get '.$key);

        if (!isset($data[0])) {
            return false;
        }

        return !isset($data[1]) || $data[1] === 'N;' ? '' : $data[1];
    }

    /**
     * Check if key exists.
     *
     * @param string $key
     *
     * @return bool
     */
    public function exists(string $key): bool {
        return $this->getKey($key) !== false;
    }
}
