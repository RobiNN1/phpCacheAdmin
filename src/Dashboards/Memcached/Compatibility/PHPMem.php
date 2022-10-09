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
use RobiNN\Pca\Helpers;

class PHPMem implements CompatibilityInterface {
    use KeysTrait;

    /**
     * @const string PHPMem version.
     */
    public const VERSION = '1.0.1';

    private string $host;

    private int $port;

    /**
     * @var array<string, int|string>
     */
    protected array $server;

    /**
     * @param array<string, int|string> $server
     */
    public function __construct(array $server = []) {
        $this->server = $server;
    }

    /**
     * Add a server to the server pool.
     *
     * @param string $host
     * @param int    $port
     *
     * @return bool
     */
    public function addServer(string $host, int $port = 11211): bool {
        $this->host = $host;
        $this->port = $port;

        return true;
    }

    /**
     * Semd data to the server.
     *
     * @param string $command
     *
     * @return string
     * @throws MemcachedException
     */
    private function send(string $command): string {
        if (isset($this->server['path'])) {
            $fp = @stream_socket_client('unix://'.$this->host, $error_code, $error_message);
        } else {
            $fp = @fsockopen($this->host, $this->port, $error_code, $error_message, 3);
        }

        if ($error_message !== '' || $fp === false) {
            throw new MemcachedException('Command: "'.$command.'": '.$error_message);
        }

        fwrite($fp, $command."\r\n");

        $buffer = '';

        while (!feof($fp)) {
            $buffer .= fgets($fp, 256);

            $ends = ['END', 'DELETED', 'NOT_FOUND', 'OK', 'EXISTS', 'ERROR', 'RESET', 'STORED', 'NOT_STORED', 'VERSION'];

            foreach ($ends as $end) {
                if (preg_match('/^'.$end.'/imu', $buffer)) {
                    break 2;
                }
            }
        }

        fclose($fp);

        return rtrim($buffer, "\r\n");
    }

    /**
     * Store an item.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $expiration
     *
     * @return bool
     * @throws MemcachedException
     */
    public function set(string $key, $value, int $expiration = 0): bool {
        $type = gettype($value);

        if ($type !== 'string' && $type !== 'integer' && $type !== 'double' && $type !== 'boolean') {
            $value = serialize($value);
        }

        // set <key> <flags> <exptime> <bytes> [noreply]\r\n<value>\r\n
        $raw = $this->send('set '.$key.' 0 '.$expiration.' '.strlen((string) $value)."\r\n".$value);

        if (Helpers::str_starts_with($raw, 'STORED')) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve an item.
     *
     * @param string $key
     *
     * @return string|false
     * @throws MemcachedException
     */
    public function get(string $key) {
        $raw = $this->send('get '.$key);
        $lines = explode("\r\n", $raw);

        if (Helpers::str_starts_with($raw, 'VALUE') && Helpers::str_ends_with($raw, 'END')) {
            return $lines[1];
        }

        return false;
    }

    /**
     * Delete item from the server.
     *
     * @param string $key
     *
     * @return bool
     * @throws MemcachedException
     */
    public function delete(string $key): bool {
        $raw = $this->send('delete '.$key);

        return $raw === 'DELETED';
    }

    /**
     * Invalidate all items in the cache.
     *
     * @return bool
     * @throws MemcachedException
     */
    public function flush(): bool {
        $raw = $this->send('flush_all');

        return $raw === 'OK';
    }

    /**
     * Check connection.
     *
     * @return bool
     */
    public function isConnected(): bool {
        try {
            $stats = $this->getServerStats();
        } catch (MemcachedException $e) {
            return false;
        }

        return isset($stats['pid']) && $stats['pid'] > 0;
    }

    /**
     * Get server statistics.
     *
     * @return array<string, mixed>
     * @throws MemcachedException
     */
    public function getServerStats(): array {
        $raw = $this->send('stats');
        $lines = explode("\r\n", $raw);
        $line_n = 0;
        $stats = [];

        while ($lines[$line_n] !== 'END') {
            $line = explode(' ', $lines[$line_n]);
            array_shift($line); // remove 'STAT' key
            [$name, $value] = $line;

            $stats[$name] = $value;

            ++$line_n;
        }

        return $stats;
    }

    /**
     * Alias to a set() but with the same order of parameters.
     *
     * @param string $key
     * @param string $value
     * @param int    $expiration
     *
     * @return bool
     * @throws MemcachedException
     */
    public function store(string $key, string $value, int $expiration = 0): bool {
        return $this->set($key, $value, $expiration);
    }
}
