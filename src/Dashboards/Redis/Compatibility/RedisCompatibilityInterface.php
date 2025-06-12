<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use RobiNN\Pca\Dashboards\DashboardException;

interface RedisCompatibilityInterface {
    /**
     * Get a key type from an array.
     */
    public function getType(string|int $type): string;

    /**
     * Get a key type.
     *
     * @throws DashboardException
     */
    public function getKeyType(string $key): string;

    /**
     * Get server info.
     *
     * @return array<int|string, mixed>
     */
    public function getInfo(?string $option = null): array;

    /**
     * Alias to a scan().
     *
     * @return array<int, string>
     */
    public function scanKeys(string $pattern, int $count): array;

    /**
     * Alias to a lRem().
     */
    public function listRem(string $key, string $value, int $count): int;

    /**
     * Alias to a xAdd().
     *
     * @param array<string, string> $messages
     */
    public function streamAdd(string $key, string $id, array $messages): string;

    /**
     * Pipeline keys for better performance.
     *
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     */
    public function pipelineKeys(array $keys): array;

    /**
     * Get key size.
     *
     * Requires Redis >= 4.0.0.
     */
    public function size(string $key): int;

    /**
     * Alias to a flushDB().
     */
    public function flushDatabase(): bool;

    /**
     * Alias to a dbSize().
     */
    public function databaseSize(): int;
}
