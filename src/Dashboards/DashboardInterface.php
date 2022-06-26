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
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string;

    /**
     * Data for info panels.
     *
     * @return array
     */
    public function info(): array;

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string;
}
