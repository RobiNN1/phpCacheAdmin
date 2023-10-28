<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached\Compatibility;

interface MemcachedCompatibilityInterface {
    public function isConnected(): bool;

    /**
     * Get server statistics.
     *
     * @return array<string, mixed>
     */
    public function getServerStats(): array;

    /**
     * Alias to a set() but with the same order of parameters.
     */
    public function store(string $key, string $value, int $expiration = 0): bool;

    /**
     * Get all the keys.
     *
     * @return array<int, mixed>
     */
    public function getKeys(): array;
}
