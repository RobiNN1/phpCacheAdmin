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

class Memcache extends \Memcache implements MemcacheInterface {
    use RunCommandTrait;

    /**
     * @var array<string, mixed>
     */
    protected array $server;

    /**
     * @param array<string, mixed> $server
     */
    public function __construct(array $server = []) {
        $this->server = $server;
    }

    /**
     * Check connection.
     *
     * @return bool
     */
    public function isConnected(): bool {
        return $this->getServerStatus($this->server['host'], (int) $this->server['port']) !== 0;
    }

    /**
     * Get server statistics.
     *
     * @return array<string, mixed>
     */
    public function getServerStats(): array {
        return (array) @$this->getStats();
    }

    /**
     * Store item.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $expiration
     *
     * @return bool
     */
    public function store(string $key, $value, int $expiration = 0): bool {
        return $this->set($key, $value, 0, $expiration);
    }
}
