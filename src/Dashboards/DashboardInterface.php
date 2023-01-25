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

namespace RobiNN\Pca\Dashboards;

interface DashboardInterface {
    /**
     * Check if an extension/class is installed.
     */
    public static function check(): bool;

    /**
     * Array that contains key, name, and optionally an icon or colors.
     *
     * @return array<string, string|array<int, string>>
     */
    public function dashboardInfo(): array;

    /**
     * Main dashboard content.
     */
    public function dashboard(): string;
}
