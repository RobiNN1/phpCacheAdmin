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

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use RobiNN\Pca\Dashboards\DashboardException;

interface CompatibilityInterface {
    /**
     * Get all data types.
     *
     * Used in form.
     *
     * @return array<string, string>
     */
    public function getAllTypes(): array;

    /**
     * Get a key type.
     *
     * @throws DashboardException
     */
    public function getType(string $key): string;

    /**
     * Alias to a lRem() but with the same order of parameters.
     */
    public function listRem(string $key, string $value, int $count): int;

    /**
     * Get server info.
     *
     * @return array<int|string, mixed>
     */
    public function getInfo(?string $option = null): array;
}
