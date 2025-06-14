<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards;

interface DashboardInterface {
    /**
     * Check if an extension/class is installed.
     */
    public static function check(): bool;

    /**
     * Array that contains a key, name, and optionally an icon or colors.
     *
     * @return array<string, array<int, string>|string>
     */
    public function dashboardInfo(): array;

    /**
     * Ajax content.
     */
    public function ajax(): string;

    /**
     * Main dashboard content.
     */
    public function dashboard(): string;
}
