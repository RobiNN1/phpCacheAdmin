<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use function explode;
use function is_numeric;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function substr_count;

class PHPMem {
    public const VERSION = '2.0.1';

    /**
     * @var resource|null
     */
    private $stream;

    private ?string $server_version = null;

    private const NO_END_COMMANDS = [
        'me' => true, 'incr' => true, 'decr' => true, 'version' => true,
        'ms' => true, 'md' => true, 'ma' => true, 'cache_memlimit' => true,
        'mn' => true, 'quit' => true, 'mg' => true,
    ];

    private const END_MARKERS = [
        "ERROR\r\n", "CLIENT_ERROR\r\n", "SERVER_ERROR\r\n", "STORED\r\n",
        "NOT_STORED\r\n", "EXISTS\r\n", "NOT_FOUND\r\n", "TOUCHED\r\n",
        "DELETED\r\n", "OK\r\n", "END\r\n", "BUSY\r\n", "BADCLASS\r\n",
        "NOSPARE\r\n", "NOTFULL\r\n", "UNSAFE\r\n", "SAME\r\n", "RESET\r\n",
        "EN\r\n",
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

        $slabs_raw = $this->runCommand('stats items');
        $keys = [];
        $seen_slabs = [];
        $seen_keys = [];

        foreach (explode("\n", $slabs_raw) as $line) {
            if (preg_match('/STAT items:(\d+):/', $line, $m)) {
                $slab_id = $m[1];

                if (isset($seen_slabs[$slab_id])) {
                    continue;
                }

                $seen_slabs[$slab_id] = true;

                $dump = $this->runCommand('stats cachedump '.$slab_id.' 100000');

                if (preg_match_all('/ITEM (\S+) \[(\d+) b; (\d+) s]/', $dump, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $item) {
                        $key_name = $item[1];

                        if (isset($seen_keys[$key_name])) {
                            continue;
                        }

                        $seen_keys[$key_name] = true;

                        $exp = ((int) $item[3] === 0) ? -1 : (int) $item[3];
                        $keys[] = 'key='.$key_name.' exp='.$exp.' la=0 cas=0 fetch=no cls=1 size='.$item[2];
                    }
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

        if (preg_match_all('/(key|exp|la|size)=(\S+)/', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [, $key, $val]) {
                switch ($key) {
                    case 'key':
                        $data['key'] = $val;
                        break;
                    case 'exp':
                        $data['exp'] = ($val === '-1') ? -1 : (int) $val;
                        break;
                    case 'la':
                    case 'size':
                        $data[$key] = (int) $val;
                        break;
                }
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
        if ($this->server_version !== null) {
            return $this->server_version;
        }

        $this->server_version = str_replace('VERSION ', '', $this->runCommand('version'));

        return $this->server_version;
    }

    /**
     * @throws MemcachedException
     */
    private function connect(): void {
        if (is_resource($this->stream)) {
            return; // Already connected
        }

        $address = isset($this->server['path']) ? 'unix://'.$this->server['path'] : 'tcp://'.$this->server['host'].':'.$this->server['port'];
        $stream = @stream_socket_client($address, $error_code, $error_message, 0.5);

        if ($stream === false) {
            throw new MemcachedException('Could not connect: '.$error_code.' - '.$error_message);
        }

        stream_set_timeout($stream, 1);
        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    public function __destruct() {
        $this->disconnect();
    }

    public function disconnect(): void {
        if (is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
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
        $this->connect();

        if (!is_resource($this->stream) || feof($this->stream)) {
            $this->disconnect();
            $this->connect();
        }

        stream_set_blocking($this->stream, true);
        fwrite($this->stream, $command);
        stream_set_blocking($this->stream, false);

        $buffer = '';
        $start = microtime(true);
        $select_timeout_usec = 100_000; // 100ms

        while (microtime(true) - $start < 5) {
            $read = [$this->stream];
            $write = null;
            $except = null;

            $num = @stream_select($read, $write, $except, 0, $select_timeout_usec);

            if ($num === false) {
                usleep(1000);
                continue;
            }

            if ($num === 0) {
                if ($this->checkCommandEnd($buffer)) {
                    break;
                }

                continue;
            }

            $chunk = fread($this->stream, 65536);

            if ($chunk === false) {
                continue;
            }

            if ($chunk === '') {
                if ($this->checkCommandEnd($buffer)) {
                    break;
                }

                continue;
            }

            $buffer .= $chunk;

            // Commands without a specific end string.
            if (isset(self::NO_END_COMMANDS[$command_name])) {
                break;
            }

            // Loop until the server returns one of these end strings.
            if ($this->checkCommandEnd($buffer)) {
                break;
            }
        }

        return $buffer;
    }

    private function checkCommandEnd(string $buffer): bool {
        if ($buffer === '') {
            return false;
        }

        foreach (self::END_MARKERS as $marker) {
            if (str_ends_with($buffer, $marker)) {
                return true;
            }
        }

        return false;
    }
}
