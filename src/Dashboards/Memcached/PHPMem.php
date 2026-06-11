<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use Memcached;
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

    private ?Memcached $memcached = null;

    /**
     * @param array<string, int|string|bool> $server
     */
    public function __construct(protected array $server = []) {
        if (isset($this->server['extension']) && $this->server['extension'] === true && extension_loaded('memcached')) {
            $this->memcached = new Memcached();

            if (isset($this->server['path'])) {
                $this->memcached->addServer($this->server['path'], 0);
            } else {
                $this->memcached->addServer($this->server['host'], (int) $this->server['port']);
            }
        }
    }

    /**
     * Store an item.
     *
     * @throws MemcachedException
     */
    public function set(string $key, mixed $value, int $expiration = 0): bool {
        if ($this->memcached instanceof Memcached) {
            return $this->memcached->set($key, $value, $expiration);
        }

        $key = preg_replace('/[\s\x00-\x1f]+/', '', $key);
        $value = is_scalar($value) ? (string) $value : serialize($value);
        $raw = $this->runCommand('set '.$key.' 0 '.$expiration.' '.strlen($value)."\r\n".$value);

        return str_starts_with($raw, 'STORED');
    }

    /**
     * Get raw key.
     *
     * @throws MemcachedException
     */
    public function get(string $key): string|false {
        if ($this->memcached instanceof Memcached) {
            $value = $this->memcached->get($key);

            if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                return false;
            }

            return is_scalar($value) ? (string) $value : serialize($value);
        }

        $key = preg_replace('/[\s\x00-\x1f]+/', '', $key);
        $this->sendCommand('get '.$key."\r\n");
        $header = $this->readLine();

        if (!str_starts_with($header, 'VALUE ')) {
            return false;
        }

        // "VALUE <key> <flags> <bytes>"
        $value = $this->readBytes((int) (explode(' ', $header)[3] ?? 0));
        $this->readLine(); // \r\n after the data block
        $this->readLine(); // END line

        return $value;
    }


    /**
     * Delete item from the server.
     *
     * @throws MemcachedException
     */
    public function delete(string $key): bool {
        $key = preg_replace('/[\s\x00-\x1f]+/', '', $key);

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
            $this->sendCommand("lru_crawler metadump all\r\n");
            $lines = $this->readLines();
            $last = rtrim((string) array_pop($lines), "\r"); // END/EN terminator

            if ($last !== 'END' && $last !== 'EN') {
                throw new MemcachedException('metadump failed: '.$last);
            }

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
     * Get key metadata.
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
        $key = preg_replace('/[\s\x00-\x1f]+/', '', $key);

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

            if (($data['key'] ?? null) === $key) {
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
        if (version_compare($this->version(), '1.5.19', '>=')) {
            $key = preg_replace('/[\s\x00-\x1f]+/', '', $key);

            return str_starts_with($this->runCommand('me '.$key), 'ME ');
        }

        return $this->get($key) !== false;
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

        stream_set_timeout($stream, 5); // Read timeout, reads return early when data arrives
        stream_set_chunk_size($stream, 65536); // With the default 8 kB, large dumps need 8x more reads
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
     * Note: \r\n is added automatically to the end.
     *
     * @link https://github.com/memcached/memcached/blob/master/doc/protocol.txt
     *
     * @throws MemcachedException
     */
    public function runCommand(string $command): string {
        $command_name = strtolower((string) strtok($command, ' '));
        $this->sendCommand($command."\r\n");

        if ($command_name === 'quit') {
            $this->disconnect(); // the server closes the connection without a reply

            return '';
        }

        // Lists of lines, terminated by an END/EN line.
        if ($command_name === 'stats' || ($command_name === 'lru_crawler' && (str_contains($command, 'metadump') || str_contains($command, 'mgdump')))) {
            return $this->readUntilEndLine();
        }

        // "VALUE <key> <flags> <bytes>" headers with data blocks, terminated by an END line.
        if (in_array($command_name, ['get', 'gets', 'gat', 'gats'], true)) {
            return implode("\r\n", $this->readValueLines());
        }

        $line = $this->readLine();

        // "mg <key> v" returns the value as a data block after the "VA <bytes> <flags>" header.
        if ($command_name === 'mg' && str_starts_with($line, 'VA ')) {
            $value = $this->readBytes((int) (explode(' ', $line)[1] ?? 0));
            $this->readLine(); // the \r\n after the data block

            return $line."\r\n".$value;
        }

        return $line;
    }

    /**
     * @throws MemcachedException
     */
    private function sendCommand(string $command): void {
        $this->connect();

        if (!is_resource($this->stream) || feof($this->stream)) {
            $this->disconnect();
            $this->connect();
        }

        $this->writeToStream($command);
    }

    /**
     * Write the whole command, fwrite() may write fewer bytes than given on a socket.
     *
     * @throws MemcachedException
     */
    private function writeToStream(string $command): void {
        $length = strlen($command);

        for ($written = 0; $written < $length; $written += $bytes) {
            $bytes = fwrite($this->stream, substr($command, $written));

            if ($bytes === false || $bytes === 0) {
                $this->disconnect();
                throw new MemcachedException('Failed to write to the socket.');
            }
        }
    }

    /**
     * Read one response line, e.g. "STORED", "VERSION 1.6.42" or a "VALUE <key> <flags> <bytes>" header.
     *
     * @throws MemcachedException
     */
    private function readLine(): string {
        $line = '';

        do {
            $chunk = fgets($this->stream);

            if ($chunk === false || $chunk === '') {
                if (feof($this->stream)) {
                    return rtrim($line, "\r\n");
                }

                $this->disconnect();
                throw new MemcachedException('Timed out while reading a response line.');
            }

            $line .= $chunk;
        } while (!str_ends_with($line, "\n"));

        return rtrim($line, "\r\n");
    }

    /**
     * @throws MemcachedException
     */
    private function readChunk(int $max_bytes): string {
        $chunk = fread($this->stream, $max_bytes);

        if ($chunk === false || $chunk === '') {
            $closed = feof($this->stream);
            $this->disconnect();

            throw new MemcachedException($closed ? 'The server closed the connection.' : 'Timed out while reading the response.');
        }

        return $chunk;
    }

    /**
     * Read a data block of an exact byte length.
     * Data can contain anything (\r\n, "END", ...), so it cannot be read line by line.
     *
     * @throws MemcachedException
     */
    private function readBytes(int $bytes): string {
        $data = '';

        while (strlen($data) < $bytes) {
            $data .= $this->readChunk(min(65536, $bytes - strlen($data)));
        }

        return $data;
    }

    /**
     * Read a multi-line response (stats, metadump) until its terminating status line (END, EN, or an error reply such as BUSY).
     *
     * Reads in bulk and only examines the last complete line, checking line by
     * line would be noticeably slower for large responses such as 'lru_crawler metadump all'.
     *
     * @throws MemcachedException
     */
    private function readUntilEndLine(): string {
        $buffer = '';

        do {
            $buffer .= $this->readChunk(65536);
        } while (!$this->endsWithStatusLine($buffer));

        return rtrim($buffer, "\r\n");
    }

    /**
     * Read a multi-line response as individual lines.
     * The terminating status line (END, EN, or an error reply such as BUSY) is the last element.
     *
     * Unlike readUntilEndLine(), the whole response is never held in memory as one string,
     * which matters for large outputs such as 'lru_crawler metadump all'.
     *
     * @return list<string>
     *
     * @throws MemcachedException
     */
    private function readLines(): array {
        $lines = [];
        $partial = '';

        while (true) {
            $split = explode("\n", $partial.$this->readChunk(65536));
            $partial = (string) array_pop($split); // incomplete last line

            if ($split === []) {
                continue;
            }

            array_push($lines, ...$split);

            if ($this->isEndOfResponse(rtrim((string) end($split), "\r"))) {
                return $lines;
            }
        }
    }

    /**
     * Check whether the last complete line is a terminating status line.
     * List responses contain no data blocks, so the terminator can only ever be the last line.
     */
    private function endsWithStatusLine(string $buffer): bool {
        if (!str_ends_with($buffer, "\n")) {
            return false; // last line is not complete yet
        }

        $newline = strlen($buffer) > 1 ? strrpos($buffer, "\n", -2) : false;
        $last_line = substr($buffer, $newline === false ? 0 : $newline + 1);

        return $this->isEndOfResponse(rtrim($last_line, "\r\n"));
    }

    /**
     * Read "VALUE <key> <flags> <bytes>" headers with their data blocks until the END line.
     *
     * @return list<string>
     *
     * @throws MemcachedException
     */
    private function readValueLines(): array {
        $parts = [];

        while (true) {
            $line = $this->readLine();

            $parts[] = $line;

            if (!str_starts_with($line, 'VALUE ')) {
                return $parts;
            }

            $parts[] = $this->readBytes((int) (explode(' ', $line)[3] ?? 0));
            $this->readLine(); // \r\n after the data block
        }
    }

    private function isEndOfResponse(string $line): bool {
        if (in_array($line, ['END', 'EN', 'OK', 'RESET', 'ERROR'], true)) {
            return true;
        }

        foreach (['ERROR ', 'CLIENT_ERROR ', 'SERVER_ERROR ', 'BUSY ', 'BADCLASS ', 'NOSPARE ', 'NOTFULL ', 'UNSAFE ', 'SAME '] as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
