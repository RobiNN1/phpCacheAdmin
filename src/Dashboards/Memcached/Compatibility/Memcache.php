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

class Memcache extends \Memcache implements CompatibilityInterface {
    use KeysTrait;

    /**
     * @var array<string, int|string>
     */
    protected array $server;

    /**
     * @param array<string, int|string> $server
     */
    public function __construct(array $server = []) {
        $this->server = $server;

        if (isset($this->server['path'])) {
            $this->addServer($this->server['path']);
        } else {
            $this->addServer($this->server['host'], (int) $this->server['port']);
        }
    }

    public function isConnected(): bool {
        // Need to be silenced since Memcache doesn't throw exceptions...
        $stats = @$this->getStats();

        return isset($stats['pid']) && $stats['pid'] > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getServerStats(): array {
        return (array) @$this->getStats();
    }

    public function store(string $key, string $value, int $expiration = 0): bool {
        return $this->set($key, $value, 0, $expiration);
    }
}
