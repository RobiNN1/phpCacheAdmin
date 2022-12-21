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
     * Run Memcached command.
     *
     * set|add|replace|append|prepend <key> <flags> <ttl> <bytes>\r\n<value>
     * cas <key> <flags> <ttl> <bytes> <cas unique>\r\n<value>
     * get|gets <key|keys>
     * gat|gats <exptime> <key|keys>
     * touch <key> <ttl>
     * delete <key>
     * incr|decr <key> <value>
     * stats [items|slabs|sizes|cachedump <slab_id> <limit>|reset]
     * flush_all
     * version
     * lru_crawler <enable|disable>
     * lru_crawler sleep <microseconds>
     * lru_crawler tocrawl <limit>
     * lru_crawler crawl <...classid|all>
     * lru_crawler metadump <...classid|all|hash>
     *
     * Note: \r\n is added automatically to the end.
     *
     * @link https://github.com/memcached/memcached/blob/master/doc/protocol.txt
     *
     * @return array<int, mixed>|string
     *
     * @throws MemcachedException
     */
    public function runCommand(string $command, bool $array = false) {
        $fp = $this->resource($command);
        $buffer = '';
        $data = [];

        while (!feof($fp)) {
            $buffer .= fgets($fp, 256);

            // These do not have a specific end string
            $no_end = [
                'incr', 'decr',
            ];

            // Loop only once
            if (in_array($this->commandName($command), $no_end)) {
                break;
            }

            $ends = [
                'ERROR', 'CLIENT_ERROR', 'SERVER_ERROR', 'STORED', 'NOT_STORED', 'EXISTS', 'NOT_FOUND', 'TOUCHED', 'DELETED',
                'OK', 'END', 'VERSION', 'BUSY', 'BADCLASS', 'NOSPARE', 'NOTFULL', 'UNSAFE', 'SAME', 'RESET',
            ];

            foreach ($ends as $end) {
                if (preg_match('/^'.$end.'/imu', $buffer)) {
                    break 2;
                }
            }

            // Bug fix for gzip, need a better solution
            if ($array === true) {
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    $data[] = $line;
                }
            }
        }

        fclose($fp);

        if ($array === true) {
            return $data;
        }

        return rtrim($buffer, "\r\n");
    }

    /**
     * @return resource
     *
     * @throws MemcachedException
     */
    private function resource(string $command) {
        if ($this->checkCommand($command) === false) {
            throw new MemcachedException('Unknown or incorrect command "'.$command.'".');
        }

        if (isset($this->server['path'])) {
            $fp = @stream_socket_client('unix://'.$this->server['path'], $error_code, $error_message);
        } else {
            $this->server['port'] ??= 11211;
            $fp = @fsockopen($this->server['host'], (int) $this->server['port'], $error_code, $error_message, 3);
        }

        if ($error_message !== '' || $fp === false) {
            throw new MemcachedException('Command: "'.$command.'": '.$error_message);
        }

        $command = strtr($command, ['\r\n' => "\r\n"]);
        $write = fwrite($fp, $command."\r\n");

        if ($write === false) {
            throw new MemcachedException('Command: "'.$command.'": Not valid resource.');
        }

        return $fp;
    }

    private function commandName(string $command): string {
        $parts = explode(' ', $command);

        return strtolower($parts[0]);
    }

    private function checkCommand(string $command): bool {
        // Unknown or incorrect commands can cause an infinite loop,
        // so only tested commands are allowed
        $allowed = [
            'set', 'add', 'replace', 'append', 'prepend', 'cas', 'get', 'gets', 'gat', 'gats',
            'touch', 'delete', 'incr', 'decr', 'stats', 'flush_all', 'version', 'lru_crawler',
        ];

        return in_array($this->commandName($command), $allowed, true);
    }
}
