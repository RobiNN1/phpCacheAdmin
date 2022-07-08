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

interface MemcacheInterface {
    /**
     * Check connection.
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Get server statistics.
     *
     * @return array<string, mixed>
     */
    public function getServerStats(): array;

    /**
     * Store item.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $expiration
     *
     * @return bool
     */
    public function store(string $key, $value, int $expiration = 0): bool;

    /**
     * Get all keys.
     *
     * @return array<int, mixed>
     */
    public function getKeys(): array;
}
