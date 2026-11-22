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
    public const VERSION = '2.1.0';

    /**
     * @var resource|null
     */
    private $stream;

    private ?string $server_version = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $default_stats = null;

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
     * @param int $expiration In seconds, 0 never expires.
     * @param int $flags      Client flags, ignored with the extension (it sets its own).
     *
     * @throws MemcachedException
     */
    public function set(string $key, mixed $value, int $expiration = 0, int $flags = 0): bool {
        $expiration = $this->expiration($expiration);

        if ($this->memcached instanceof Memcached) {
            return $this->memcached->set($key, $value, $expiration);
        }

        $key = $this->cleanKey($key);
        $value = is_scalar($value) ? (string) $value : serialize($value);
        $raw = $this->runCommand('set '.$key.' '.max(0, $flags).' '.$expiration.' '.strlen($value)."\r\n".$value);

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

        if ($this->hasMetaCommands()) {
            return $this->getItem($key)['value'] ?? false;
        }

        $key = $this->cleanKey($key);
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
     * Read an item with its metadata.
     *
     * Since 1.6 this is a single "mg" with the "u" flag, so the item is not bumped in the LRU,
     * and viewing it does not change its last access time or mark it as fetched.
     *
     * @return array{value: string, flags: int, size: int, exp: int, la: ?int, fetch: ?bool, cas: ?int}|null
     *
     * @throws MemcachedException
     */
    public function getItem(string $key): ?array {
        $key = $this->cleanKey($key);

        if (!$this->hasMetaCommands()) {
            $raw = $this->runCommand('gets '.$key);
            $newline = strpos($raw, "\r\n");

            if ($newline === false || !str_starts_with($raw, 'VALUE ')) {
                return null;
            }

            // "VALUE <key> <flags> <bytes> <cas>"
            $header = explode(' ', substr($raw, 0, $newline));
            $value = substr($raw, $newline + 2, (int) ($header[3] ?? 0));
            $meta = $this->getKeyMeta($key);

            return [
                'value' => $value,
                'flags' => (int) ($header[2] ?? 0),
                'size'  => strlen($value),
                'exp'   => (int) ($meta['exp'] ?? -1),
                'la'    => isset($meta['la']) ? (int) $meta['la'] : null,
                'fetch' => $meta['fetch'] ?? null,
                'cas'   => isset($header[4]) ? (int) $header[4] : null,
            ];
        }

        $raw = $this->runCommand('mg '.$key.' s t l h f c v u');

        if (!str_starts_with($raw, 'VA ')) {
            return null;
        }

        [$header, $value] = explode("\r\n", $raw, 2) + [1 => ''];
        $meta = $this->parseMetaFlags(array_slice(explode(' ', $header), 2));

        if ($this->memcached instanceof Memcached) {
            $value = (string) $this->get($key); // the extension decodes the value
        }

        return ['value' => $value] + $meta;
    }

    /**
     * Change the expiration without rewriting the value.
     *
     * @param int $expiration In seconds, 0 never expires.
     *
     * @throws MemcachedException
     */
    public function touch(string $key, int $expiration): bool {
        $key = $this->cleanKey($key);
        $expiration = $this->expiration($expiration);

        // "touch" counts as a fetch, "mg" with "u" changes only the TTL.
        if ($this->hasMetaCommands()) {
            return $this->runCommand('mg '.$key.' T'.$expiration.' u') === 'HD';
        }

        return $this->runCommand('touch '.$key.' '.$expiration) === 'TOUCHED';
    }

    /**
     * Delete item from the server.
     *
     * @throws MemcachedException
     */
    public function delete(string $key): bool {
        return $this->runCommand('delete '.$this->cleanKey($key)) === 'DELETED';
    }

    /**
     * Delete many keys, pipelined with "noreply" so that each key does not wait for a round trip.
     *
     * @param iterable<string> $keys
     *
     * @return int Number of keys sent, a key that was already gone is counted too.
     *
     * @throws MemcachedException
     */
    public function deleteMany(iterable $keys, int $batch = 500): int {
        $sent = 0;
        $buffer = '';
        $buffered = 0;

        foreach ($keys as $key) {
            $key = $this->cleanKey($key);

            if ($key === '') {
                continue;
            }

            $buffer .= 'delete '.$key." noreply\r\n";
            $sent++;

            if (++$buffered >= $batch) {
                $this->syncNoreply($buffer);
                $buffer = '';
                $buffered = 0;
            }
        }

        if ($buffer !== '') {
            $this->syncNoreply($buffer);
        }

        return $sent;
    }

    /**
     * Send noreply commands followed by "version", the reply to it means all of them were processed.
     *
     * @throws MemcachedException
     */
    private function syncNoreply(string $commands): void {
        $this->sendCommand($commands."version\r\n");

        // A malformed command is answered with an error even with noreply, skip those lines.
        do {
            $line = $this->readLine();
        } while (!str_starts_with($line, 'VERSION') && $line !== '');
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
        if ($type === null && $this->default_stats !== null) {
            return $this->default_stats;
        }

        $suffix = in_array($type, ['settings', 'items', 'sizes', 'slabs', 'conns'], true) ? ' '.$type : '';
        $raw = $this->runCommand('stats'.$suffix);
        $stats = [];

        foreach (explode("\r\n", $raw) as $line) {
            if (str_starts_with($line, 'STAT')) {
                [, $key, $value] = explode(' ', $line, 3);
                $stats[$key] = is_numeric($value) ? (int) $value : $value;
            }
        }

        if ($type === null) {
            $this->default_stats = $stats;
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
     * Get all keys.
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

            // Memcached >= 1.6.43 prefixes the dump with an OK line
            if ($lines !== [] && rtrim($lines[0], "\r") === 'OK') {
                array_shift($lines);
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
                        $keys[] = 'key='.rawurlencode($key_name).' exp='.$exp.' la=0 cas=0 fetch=no cls=1 size='.$item[2];
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Convert a raw key line to an array.
     *
     * @return array<string, string|int|bool>
     */
    public function parseLine(string $line): array {
        $data = [];

        if (preg_match_all('/(?:^| )(key|exp|la|size|fetch|cls|flags)=(\S+)/', $line, $matches, PREG_SET_ORDER)) {
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
                    case 'cls':
                    case 'flags':
                        $data[$key] = (int) $val;
                        break;
                    case 'fetch':
                        $data['fetch'] = $val === 'yes';
                        break;
                }
            }
        }

        return $data;
    }

    /**
     * Get key metadata, requires Memcached >= 1.5.19.
     *
     * "exp" is the remaining TTL in seconds (-1 never expires), "la" the seconds since the last access.
     *
     * @link https://github.com/memcached/memcached/wiki/ReleaseNotes1519
     *
     * @return array<string, string|int|bool|null>
     *
     * @throws MemcachedException
     */
    public function getKeyMeta(string $key): array {
        $key = $this->cleanKey($key);

        if ($this->hasMetaCommands()) {
            $raw = $this->runCommand('mg '.$key.' s t l h f c u');

            if (!str_starts_with($raw, 'HD')) {
                return [];
            }

            return $this->parseMetaFlags(array_slice(explode(' ', $raw), 1));
        }

        if (version_compare($this->version(), '1.5.19', '>=')) {
            $raw = $this->runCommand('me '.$key);

            if (!str_starts_with($raw, 'ME ')) {
                return [];
            }

            $raw = preg_replace('/^ME\s+\S+\s+/', '', $raw); // Remove `ME keyname`
            $data = $this->parseLine($raw);

            // Before 1.6.24 `me` returned the remaining TTL negated (exp=-120 for 120 s left).
            if (isset($data['exp']) && $data['exp'] < -1 && version_compare($this->version(), '1.6.24', '<')) {
                $data['exp'] = -$data['exp'];
            }

            return $data;
        }

        foreach ($this->getKeys() as $line) {
            $data = $this->parseLine($line);

            if (urldecode((string) ($data['key'] ?? '')) !== $key) {
                continue;
            }

            if (isset($data['exp']) && $data['exp'] !== -1) {
                $data['exp'] = max(0, (int) $data['exp'] - $this->serverTime());
            }

            return $data;
        }

        return [];
    }

    /**
     * The flags of a "mg" reply, e.g. ["s5", "t-1", "l12", "h0", "f0", "c3"].
     *
     * @param array<int, string> $tokens
     *
     * @return array{flags: int, size: int, exp: int, la: ?int, fetch: ?bool, cas: ?int}
     */
    private function parseMetaFlags(array $tokens): array {
        $meta = ['flags' => 0, 'size' => 0, 'exp' => -1, 'la' => null, 'fetch' => null, 'cas' => null];

        foreach ($tokens as $token) {
            $value = substr($token, 1);

            match ($token[0] ?? '') {
                's' => $meta['size'] = (int) $value,
                't' => $meta['exp'] = (int) $value,
                'l' => $meta['la'] = (int) $value,
                'h' => $meta['fetch'] = $value === '1',
                'f' => $meta['flags'] = (int) $value,
                'c' => $meta['cas'] = (int) $value,
                default => null,
            };
        }

        return $meta;
    }

    /**
     * Check if the key exists, it does not count as access.
     *
     * @throws MemcachedException
     */
    public function exists(string $key): bool {
        if ($this->hasMetaCommands()) {
            return str_starts_with($this->runCommand('mg '.$this->cleanKey($key).' u'), 'HD');
        }

        if (version_compare($this->version(), '1.5.19', '>=')) {
            return str_starts_with($this->runCommand('me '.$this->cleanKey($key)), 'ME ');
        }

        return $this->get($key) !== false;
    }

    /**
     * Meta commands (mg, ms, ...) are stable since 1.6.0.
     *
     * @throws MemcachedException
     */
    public function hasMetaCommands(): bool {
        return version_compare($this->version(), '1.6.0', '>=');
    }

    /**
     * The server's clock, metadump times (exp, la) are absolute timestamps of this clock.
     *
     * @throws MemcachedException
     */
    public function serverTime(): int {
        return (int) ($this->getServerStats()['time'] ?? time());
    }

    /**
     * Whitespace and control characters are not allowed in keys of the text protocol.
     */
    private function cleanKey(string $key): string {
        return preg_replace('/[\s\x00-\x1f]+/', '', $key);
    }

    /**
     * Memcached reads an expiration over 30 days as a unix timestamp, so longer TTLs are converted to one.
     *
     * @throws MemcachedException
     */
    private function expiration(int $seconds): int {
        if ($seconds <= 0) {
            return 0;
        }

        return $seconds > 2_592_000 ? $this->serverTime() + $seconds : $seconds;
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
     * Run a raw command, \r\n is appended automatically.
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

        // A metadump is prefixed with an "OK" line since 1.6.43, which is not its terminator (that is END/EN).
        if ($command_name === 'lru_crawler' && (str_contains($command, 'metadump') || str_contains($command, 'mgdump'))) {
            return $this->readUntilEndLine(false);
        }

        if ($command_name === 'stats') {
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
     * Read a data block of an exact byte length, it can contain \r\n or END.
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
     * Read a multi-line response until its terminating status line (END, EN or an error such as BUSY).
     *
     * @throws MemcachedException
     */
    private function readUntilEndLine(bool $ok_terminates = true): string {
        $buffer = '';

        do {
            $buffer .= $this->readChunk(65536);
        } while (!$this->endsWithStatusLine($buffer, $ok_terminates));

        return rtrim($buffer, "\r\n");
    }

    /**
     * Read a multi-line response as lines, the terminating status line is the last element.
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

            // Used only for a metadump, where the leading "OK" is a prefix, not the terminator.
            if ($this->isEndOfResponse(rtrim((string) end($split), "\r"), false)) {
                return $lines;
            }
        }
    }

    /**
     * Check whether the last complete line is a terminating status line.
     */
    private function endsWithStatusLine(string $buffer, bool $ok_terminates = true): bool {
        if (!str_ends_with($buffer, "\n")) {
            return false; // last line is not complete yet
        }

        $newline = strlen($buffer) > 1 ? strrpos($buffer, "\n", -2) : false;
        $last_line = substr($buffer, $newline === false ? 0 : $newline + 1);

        return $this->isEndOfResponse(rtrim($last_line, "\r\n"), $ok_terminates);
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

    public function isEndOfResponse(string $line, bool $ok_terminates = true): bool {
        if (in_array($line, ['END', 'EN', 'RESET', 'ERROR'], true)) {
            return true;
        }

        if ($ok_terminates && $line === 'OK') {
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
