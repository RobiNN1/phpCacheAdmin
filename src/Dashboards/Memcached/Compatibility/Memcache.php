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

class Memcache extends \Memcache implements CompatibilityInterface {
    use CommandTrait;

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
     * Check connection.
     *
     * @return bool
     */
    public function isConnected(): bool {
        $stats = @$this->getStats(); // Need to be silenced since Memcache doesn't throw exceptions...

        return isset($stats['pid']) && $stats['pid'] > 0;
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
     * @param string $value
     * @param int    $expiration
     *
     * @return bool
     */
    public function store(string $key, string $value, int $expiration = 0): bool {
        return $this->set($key, $value, 0, $expiration);
    }

    /**
     * SASL authentication.
     *
     * @return void
     * @throws MemcachedException
     */
    public function sasl(): void {
        throw new MemcachedException('Memcache extension does not support SASL authentication.');
    }
}
