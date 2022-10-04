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

interface CompatibilityInterface {
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
     * Alias to a set() but with the same order of parameters.
     *
     * @param string $key
     * @param string $value
     * @param int    $expiration
     *
     * @return bool
     */
    public function store(string $key, string $value, int $expiration = 0): bool;

    /**
     * Get all keys.
     *
     * @return array<int, mixed>
     */
    public function getKeys(): array;
}
