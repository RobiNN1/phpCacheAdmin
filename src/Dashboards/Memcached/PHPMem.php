<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use function explode;
use function fgets;
use function is_numeric;
use function str_ends_with;

class PHPMem {
    public const VERSION = '2.0.0';

    /**
     * @param array<string, int|string> $server
     */
    public function __construct(protected array $server = []) {
    }

    /**
     * Store an item.
     *
     * @throws MemcachedException
     */
    public function set(string $key, mixed $value, int $expiration = 0): bool {
        $value = is_scalar($value) ? (string) $value : serialize($value);
        $raw = $this->runCommand('set '.$key.' 0 '.$expiration.' '.strlen($value)."\r\n".$value);

        return str_starts_with($raw, 'STORED');
    }

    /**
     * Retrieve an item.
     *
     * @throws MemcachedException
     */
    public function get(string $key): string|false {
        $raw = $this->runCommand('get '.$key);
        $lines = explode("\r\n", $raw);

        if (str_starts_with($raw, 'VALUE') && str_ends_with($raw, 'END')) {
            return $lines[1];
        }

        return false;
    }

    /**
     * Delete item from the server.
     *
     * @throws MemcachedException
     */
    public function delete(string $key): bool {
        return $this->runCommand('delete '.$key) === 'DELETED';
    }

    /**
     * Invalidate all items in the cache.
     *
     * @throws MemcachedException
     */
    public function flush(): bool {
        return $this->runCommand('flush_all') === 'OK';
    }

    /**
     * @param 'settings'|'items'|'sizes'|'slabs'|'conns'|null $type
     *
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    public function getServerStats(?string $type = null): array {
        $type = in_array($type, ['settings', 'items', 'sizes', 'slabs', 'conns'], true) ? ' '.$type : '';
        $raw = $this->runCommand('stats'.$type);
        $stats = [];

        foreach (explode("\r\n", $raw) as $line) {
            if (str_starts_with($line, 'STAT')) {
                [, $key, $value] = explode(' ', $line, 3);
                $stats[$key] = is_numeric($value) ? (int) $value : $value;
            }
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    public function getSlabsStats(): array {
        $stats = $this->getServerStats('slabs');
        $result = [
            'slabs' => [],
            'meta'  => [],
        ];

        foreach ($stats as $key => $value) {
            if (str_contains($key, ':')) {
                [$slab_id, $field] = explode(':', $key, 2);
                $result['slabs'][$slab_id][$field] = $value;
            } else {
                $result['meta'][$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    public function getItemsStats(): array {
        $stats = $this->getServerStats('items');
        $result = [];

        foreach ($stats as $key => $value) {
            if (str_starts_with($key, 'items:') && substr_count($key, ':') === 2) {
                [, $slab_id, $field] = explode(':', $key, 3);
                $result[$slab_id][$field] = $value;
            }
        }

        return $result;
    }

    public function isConnected(): bool {
        try {
            $stats = $this->getServerStats();

            return isset($stats['pid']) && $stats['pid'] > 0;
        } catch (MemcachedException) {
            return false;
        }
    }

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
        if (version_compare($this->version(), '1.5.19', '>=')) {
            $raw = $this->runCommand('lru_crawler metadump all');
            $lines = explode("\n", $raw);
            array_pop($lines);

            return $lines;
        }

        $slabs = $this->runCommand('stats items');
        $lines = explode("\n", $slabs);
        $slab_ids = [];

        foreach ($lines as $line) {
            if (preg_match('/STAT items:(\d+):/', $line, $matches)) {
                $slab_ids[] = $matches[1];
            }
        }

        $keys = [];

        foreach (array_unique($slab_ids) as $slab_id) {
            $dump = $this->runCommand('stats cachedump '.$slab_id.' 0');
            $dump_lines = explode("\n", $dump);

            foreach ($dump_lines as $line) {
                if (preg_match('/ITEM (\S+) \[(\d+) b; (\d+) s\]/', $line, $matches)) {
                    $exp = (int) $matches[3] === 0 ? -1 : (int) $matches[3];
                    // Intentionally formatted as lru_crawler output
                    $keys[] = 'key='.$matches[1].' exp='.$exp.' la=0 cas=0 fetch=no cls=1 size='.$matches[2];
                }
            }
        }

        return $keys;
    }

    /**
     * Convert a raw key line to an array.
     *
     * @return array<string, string|int>
     */
    public function parseLine(string $line): array {
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
     * Get key meta-data.
     *
     * This command requires Memcached server >= 1.5.19
     *
     * @link https://github.com/memcached/memcached/wiki/ReleaseNotes1519
     *
     * @return array<string, string|int>
     *
     * @throws MemcachedException
     */
    public function getKeyMeta(string $key): array {
        if (version_compare($this->version(), '1.5.19', '>=')) {
            $raw = $this->runCommand('me '.$key);

            if ($raw === 'ERROR') {
                return [];
            }

            $raw = preg_replace('/^ME\s+\S+\s+/', '', $raw); // Remove `ME keyname`

            return $this->parseLine($raw);
        }

        foreach ($this->getKeys() as $line) {
            $data = $this->parseLine($line);

            if ($data['key'] === $key) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Check if the key exists.
     *
     * @throws MemcachedException
     */
    public function exists(string $key): bool {
        return $this->getKey($key) !== false;
    }

    /**
     * @throws MemcachedException
     */
    public function version(): string {
        return str_replace('VERSION ', '', $this->runCommand('version'));
    }

    /**
     * Run command.
     *
     * These commands should work but are not guaranteed to work on any server:
     *
     * set|add|replace|append|prepend  <key> <flags> <ttl> <bytes>\r\n<value>\r\n
     * cas <key> <flags> <exptime> <bytes> <cas unique>\r\n
     * get|gets <key>*\r\n
     * gat|gats <exptime> <key>*\r\n
     * delete <key>\r\n
     * incr|decr <key> <value>\r\n
     * touch <key> <exptime>\r\n
     * me <key> <flag>\r\n
     * mg <key> <flags>*\r\n
     * ms <key> <datalen> <flags>*\r\n
     * md <key> <flags>*\r\n
     * ma <key> <flags>*\r\n
     * mn\r\n
     * slabs reassign <source class> <dest class>\r\n
     * slabs automove <0|1|2>\r\n
     * lru <tune|mode|temp_ttl> <option list>\r\n
     * lru_crawler <enable|disable>\r\n
     * lru_crawler sleep <microseconds>\r\n
     * lru_crawler tocrawl <32u>\r\n
     * lru_crawler crawl <classid,classid,classid|all>\r\n
     * lru_crawler metadump <classid,classid,classid|all|hash>\r\n
     * lru_crawler mgdump <classid,classid,classid|all|hash>\r\n
     * watch <arg1> <arg2> <arg3>\r\n
     * stats <settings|items|sizes|slabs|conns>\r\n
     * stats cachedump <slab_id> <limit>\r\n
     * flush_all\r\n
     * cache_memlimit <limit>\r\n
     * shutdown\r\n
     * version\r\n
     * verbosity <level>\r\n
     * quit\r\n
     *
     * Note: \r\n is added automatically to the end,
     * and \r\n (as a plain string) is converted to a real end of the line.
     *
     * @link https://github.com/memcached/memcached/blob/master/doc/protocol.txt
     *
     * @throws MemcachedException
     */
    public function runCommand(string $command): string {
        $command_name = strtolower(strtok($command, ' '));
        $command = strtr($command, ['\r\n' => "\r\n"])."\r\n";
        $data = $this->streamConnection($command, $command_name);

        return rtrim($data, "\r\n");
    }

    /**
     * @throws MemcachedException
     */
    private function streamConnection(string $command, string $command_name): string {
        $address = isset($this->server['path']) ? 'unix://'.$this->server['path'] : 'tcp://'.$this->server['host'].':'.$this->server['port'];
        $stream = @stream_socket_client($address, $error_code, $error_message, 0.5);

        if ($stream === false) {
            throw new MemcachedException('Command: "'.$command.'": '.$error_code.' - '.$error_message);
        }

        stream_set_timeout($stream, 1);
        fwrite($stream, $command);

        $buffer = '';

        while (!feof($stream)) {
            $line = fgets($stream, 4096);

            if ($line === false) {
                break;
            }

            $buffer .= $line;

            // Commands without a specific end string.
            if ($command_name === 'incr' || $command_name === 'decr' || $command_name === 'version' ||
                $command_name === 'me' || $command_name === 'mg' || $command_name === 'ms' ||
                $command_name === 'md' || $command_name === 'ma' || $command_name === 'mn' ||
                $command_name === 'cache_memlimit' || $command_name === 'quit') {
                break;
            }

            // Loop until the server returns one of these end strings.
            if ($this->checkCommandEnd($buffer)) {
                break;
            }
        }

        fclose($stream);

        return $buffer;
    }

    private function checkCommandEnd(string $buffer): bool {
        return
            str_ends_with($buffer, "ERROR\r\n") ||
            str_ends_with($buffer, "CLIENT_ERROR\r\n") ||
            str_ends_with($buffer, "SERVER_ERROR\r\n") ||
            str_ends_with($buffer, "STORED\r\n") ||
            str_ends_with($buffer, "NOT_STORED\r\n") ||
            str_ends_with($buffer, "EXISTS\r\n") ||
            str_ends_with($buffer, "NOT_FOUND\r\n") ||
            str_ends_with($buffer, "TOUCHED\r\n") ||
            str_ends_with($buffer, "DELETED\r\n") ||
            str_ends_with($buffer, "OK\r\n") ||
            str_ends_with($buffer, "END\r\n") ||
            str_ends_with($buffer, "BUSY\r\n") ||
            str_ends_with($buffer, "BADCLASS\r\n") ||
            str_ends_with($buffer, "NOSPARE\r\n") ||
            str_ends_with($buffer, "NOTFULL\r\n") ||
            str_ends_with($buffer, "UNSAFE\r\n") ||
            str_ends_with($buffer, "SAME\r\n") ||
            str_ends_with($buffer, "RESET\r\n") ||
            str_ends_with($buffer, "EN\r\n");
    }
}
