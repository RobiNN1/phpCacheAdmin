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
    /**
     * @return array<int, mixed>
     * @throws MemcachedException
     */
    public function command(string $command): array {
        if (isset($this->server['path'])) {
            $fp = @stream_socket_client('unix://'.$this->server['path'], $error_code, $error_message);
        } else {
            $this->server['port'] ??= 11211;

            $fp = @fsockopen($this->server['host'], (int) $this->server['port'], $error_code, $error_message, 3);
        }

        if ($error_message !== '' || $fp === false) {
            throw new MemcachedException('Command: "'.$command.'": '.$error_message);
        }

        fwrite($fp, $command."\r\n");

        $buffer = '';
        $data = [];

        while (!feof($fp)) {
            $buffer .= fgets($fp, 1024);

            $ends = ['END', 'DELETED', 'NOT_FOUND', 'OK', 'EXISTS', 'ERROR', 'RESET', 'STORED', 'NOT_STORED', 'VERSION'];

            foreach ($ends as $end) {
                if (preg_match('/^'.$end.'/imu', $buffer)) {
                    break 2;
                }
            }

            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                $data[] = $line;
            }
        }

        fclose($fp);

        return $data;
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

        $all_keys = $this->command('lru_crawler metadump all');

        foreach ($all_keys as $line) {
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
        $data = $this->command('get '.$key);

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
}
