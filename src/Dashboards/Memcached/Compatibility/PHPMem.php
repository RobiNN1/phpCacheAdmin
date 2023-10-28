<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached\Compatibility;

use RobiNN\Pca\Dashboards\Memcached\MemcachedException;

class PHPMem implements MemcachedCompatibilityInterface {
    use MemcachedKeys;

    public const VERSION = '1.1.0';

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
     * Store an item.
     *
     * @param mixed $value
     *
     * @throws MemcachedException
     */
    public function set(string $key, $value, int $expiration = 0): bool {
        $type = gettype($value);

        if ($type !== 'string' && $type !== 'integer' && $type !== 'double' && $type !== 'boolean') {
            $value = serialize($value);
        }

        $raw = $this->runCommand('set '.$key.' 0 '.$expiration.' '.strlen((string) $value)."\r\n".$value);

        return str_starts_with($raw, 'STORED');
    }

    /**
     * Retrieve an item.
     *
     * @return string|false
     *
     * @throws MemcachedException
     */
    public function get(string $key) {
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
        $raw = $this->runCommand('delete '.$key);

        return $raw === 'DELETED';
    }

    /**
     * Invalidate all items in the cache.
     *
     * @throws MemcachedException
     */
    public function flush(): bool {
        $raw = $this->runCommand('flush_all');

        return $raw === 'OK';
    }

    public function isConnected(): bool {
        try {
            $stats = $this->getServerStats();
        } catch (MemcachedException $e) {
            return false;
        }

        return isset($stats['pid']) && $stats['pid'] > 0;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    public function getServerStats(): array {
        $raw = $this->runCommand('stats');
        $lines = explode("\r\n", $raw);
        $line_n = 0;
        $stats = [];

        while ($lines[$line_n] !== 'END') {
            $line = explode(' ', $lines[$line_n]);
            array_shift($line); // remove 'STAT'
            [$name, $value] = $line;

            $stats[$name] = $value;

            $line_n++;
        }

        return $stats;
    }

    /**
     * @throws MemcachedException
     */
    public function store(string $key, string $value, int $expiration = 0): bool {
        return $this->set($key, $value, $expiration);
    }
}
