<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

class PHPMem {
    public const VERSION = '2.0.0';

    /**
     * Unknown or incorrect commands can cause an infinite loop,
     * so only tested commands are allowed.
     *
     * @var array<int, string>
     */
    private array $allowed_commands = [
        'set', 'add', 'replace', 'append', 'prepend', 'cas', 'get', 'gets', 'gat', 'gats',
        'touch', 'delete', 'incr', 'decr', 'stats', 'flush_all', 'version', 'lru_crawler',
        'lru', 'slabs', 'me', 'mg', 'ms', 'md', 'ma', 'cache_memlimit', 'verbosity', 'quit',
    ];

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
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    public function getServerStats(): array {
        $raw = $this->runCommand('stats');
        $stats = [];

        foreach (explode("\r\n", $raw) as $line) {
            if (str_starts_with($line, 'STAT')) {
                [, $key, $value] = explode(' ', $line, 3);
                $stats[$key] = $value;
            }
        }

        return $stats;
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
        $raw = $this->runCommand('lru_crawler metadump all');
        $lines = explode("\n", $raw);
        array_pop($lines);

        return $lines;
    }

    /**
     * Convert raw key line to an array.
     *
     * @return array<string, string|int>
     */
    public function parseLine(string $line): array {
        $data = [];

        foreach (\explode(' ', $line) as $part) {
            if ($part !== '') {
                [$key, $val] = \explode('=', $part);
                $data[$key] = \is_numeric($val) ? (int) $val : $val;
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
     * @return array<string, string|int>
     * @throws MemcachedException
     */
    public function getKeyMeta(string $key): array {
        $raw = $this->runCommand('me '.$key);
        $raw = preg_replace('/^ME\s+\S+\s+/', '', $raw); // Remove `ME keyname`

        return $this->parseLine($raw);
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
     * Run command.
     *
     * These commands should work but not guaranteed to work on any server:
     *
     * set|add|replace|append|prepend <key> <flags> <ttl> <bytes>\r\n<value>
     * cas <key> <flags> <ttl> <bytes> <cas unique>\r\n<value>
     * get|gets <key|keys>
     * gat|gats <ttl> <key|keys>
     * touch <key> <ttl>
     * delete <key>
     * incr|decr <key> <value>
     * stats [items|slabs|sizes|cachedump <slab_id> <limit>|reset|conns]
     * flush_all
     * version
     * lru <tune|mode|temp_ttl> <option list>
     * lru_crawler <<enable|disable>|sleep <microseconds>|tocrawl <limit>|crawl|mgdump <...classid|all>|metadump <...classid|all|hash>>
     * slabs <reassign <source class> <dest class>|automove <0|1|2>>
     * me <key> <flag>
     * mg <key> <flags>*
     * ms <key> <datalen> <flags>*\r\n<value>
     * md <key> <flags>*
     * ma <key> <flags>*
     * cache_memlimit <megabytes>
     * verbosity <level>
     *
     * Note: \r\n is added automatically to the end
     * and \r\n (as a plain string) is converted to a real end of line.
     *
     * @link https://github.com/memcached/memcached/blob/master/doc/protocol.txt
     *
     * @throws MemcachedException
     */
    public function runCommand(string $command): string {
        $command_name = strtolower(strtok($command, ' '));

        if (!in_array($command_name, $this->allowed_commands, true)) {
            throw new MemcachedException('Unknown or not allowed command "'.$command.'".');
        }

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
            $line = \fgets($stream, 4096);

            if ($line === false) {
                break;
            }

            $buffer .= $line;

            // Commands without a specific end string.
            if ($command_name === 'incr' || $command_name === 'decr' || $command_name === 'version' ||
                $command_name === 'me' || $command_name === 'mg' || $command_name === 'ms' ||
                $command_name === 'md' || $command_name === 'ma' || $command_name === 'cache_memlimit' ||
                $command_name === 'quit') {
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
            \str_ends_with($buffer, "ERROR\r\n") ||
            \str_ends_with($buffer, "CLIENT_ERROR\r\n") ||
            \str_ends_with($buffer, "SERVER_ERROR\r\n") ||
            \str_ends_with($buffer, "STORED\r\n") ||
            \str_ends_with($buffer, "NOT_STORED\r\n") ||
            \str_ends_with($buffer, "EXISTS\r\n") ||
            \str_ends_with($buffer, "NOT_FOUND\r\n") ||
            \str_ends_with($buffer, "TOUCHED\r\n") ||
            \str_ends_with($buffer, "DELETED\r\n") ||
            \str_ends_with($buffer, "OK\r\n") ||
            \str_ends_with($buffer, "END\r\n") ||
            \str_ends_with($buffer, "BUSY\r\n") ||
            \str_ends_with($buffer, "BADCLASS\r\n") ||
            \str_ends_with($buffer, "NOSPARE\r\n") ||
            \str_ends_with($buffer, "NOTFULL\r\n") ||
            \str_ends_with($buffer, "UNSAFE\r\n") ||
            \str_ends_with($buffer, "SAME\r\n") ||
            \str_ends_with($buffer, "RESET\r\n") ||
            \str_ends_with($buffer, "EN\r\n");
    }
}
