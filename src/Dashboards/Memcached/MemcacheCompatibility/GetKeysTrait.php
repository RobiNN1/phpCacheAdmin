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

trait GetKeysTrait {
    /**
     * Run command.
     *
     * @param string $command
     *
     * @return array<mixed, mixed>
     */
    private function runCommand(string $command): array {
        static $data = [];

        $fp = fsockopen($this->server['host'], (int) $this->server['port']);

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

        foreach ($this->runCommand('lru_crawler metadump all') as $line) {
            $keys[] = $this->keyData($line);
        }

        return $keys;
    }
}
