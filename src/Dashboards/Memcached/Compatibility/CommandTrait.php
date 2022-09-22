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

trait CommandTrait {
    /**
     * Run command.
     *
     * @param string $command https://github.com/memcached/memcached/wiki/Commands
     *
     * @return ?array<int, mixed>
     * @throws MemcachedException
     */
    public function command(string $command): ?array {
        if (isset($this->server['path'])) {
            $fp = @stream_socket_client('unix://'.$this->server['path'], $error_code, $error_message);
        } else {
            $this->server['port'] ??= 11211;

            $fp = @fsockopen($this->server['host'], (int) $this->server['port'], $error_code, $error_message, 3);
        }

        if ($error_message !== '') {
            throw new MemcachedException('command() method: '.$error_message);
        }

        if ($fp === false) {
            return null;
        }

        fwrite($fp, $command."\r\n");

        $part = '';
        $data = [];

        while (!feof($fp)) {
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
     * Format key output.
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
     * This command requires Memcached server >= 1.4.18
     * https://github.com/memcached/memcached/wiki/ReleaseNotes1418#lru-crawler
     *
     * @return array<int, mixed>
     * @throws MemcachedException
     */
    public function getKeys(): array {
        static $keys = [];

        if (isset($this->server['sasl_username'], $this->server['sasl_password'])) {
            // for future updates, currently it is not possible to list all keys with enabled SASL
            return [];
        }

        $all_keys = $this->command('lru_crawler metadump all');

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
     * @throws MemcachedException
     */
    public function getKey(string $key) {
        $data = $this->command('get '.$key);

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
     * @throws MemcachedException
     */
    public function exists(string $key): bool {
        return $this->getKey($key) !== false;
    }
}
