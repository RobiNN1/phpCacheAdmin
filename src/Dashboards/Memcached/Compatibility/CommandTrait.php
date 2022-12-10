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
    private function checkCommand(string $command): bool {
        // Unknown or incorrect commands can cause an infinite loop.
        $allowed = [
            'set', 'add', 'replace', 'append', 'prepend', 'cas', 'get', 'gets', 'gat', 'gats',
            'touch', 'delete', 'incr', 'decr', 'stats', 'flush_all', 'version', 'lru_crawler',
        ];

        $parts = explode(' ', $command);

        return in_array(strtolower($parts[0]), $allowed, true);
    }

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
     * @throws MemcachedException
     */
    public function runCommand(string $command): string {
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

        $write = fwrite($fp, $command."\r\n");

        $buffer = '';

        if ($write === false) {
            throw new MemcachedException('Command: "'.$command.'": Not valid resource.');
        }

        while (!feof($fp)) {
            $buffer .= fgets($fp, 256);

            $ends = [
                'ERROR', 'CLIENT_ERROR', 'SERVER_ERROR', 'STORED', 'NOT_STORED', 'EXISTS', 'NOT_FOUND', 'TOUCHED', 'DELETED',
                'OK', 'END', 'VERSION', 'BUSY', 'BADCLASS', 'NOSPARE', 'NOTFULL', 'UNSAFE', 'SAME', 'EN', 'MN', 'RESET',
            ];

            foreach ($ends as $end) {
                if (preg_match('/^'.$end.'/imu', $buffer)) {
                    break 2;
                }
            }
        }

        fclose($fp);

        return rtrim($buffer, "\r\n");
    }
}
