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

namespace RobiNN\Pca;

use RobiNN\Pca\Dashboards\DashboardInterface;

class Admin {
    /**
     * @const string phpCacheAdmin version.
     */
    public const VERSION = '1.0.1';

    /**
     * @var array<string, DashboardInterface>
     */
    private array $dashboards = [];

    /**
     * @param ?Template $template
     */
    public function __construct(?Template $template = null) {
        foreach (Config::get('dashboards') as $class) {
            $dashboard = new $class($template);

            if ($dashboard instanceof DashboardInterface && $dashboard->check()) {
                $info = $dashboard->getDashboardInfo();
                $this->dashboards[$info['key']] = $dashboard;
            }
        }
    }

    /**
     * Get all dashboards.
     *
     * @return array<string, DashboardInterface>
     */
    public function getDashboards(): array {
        return $this->dashboards;
    }

    /**
     * Get dashboard object.
     *
     * @param string $dashboard
     *
     * @return object
     */
    public function getDashboard(string $dashboard): object {
        return $this->dashboards[$dashboard];
    }

    /**
     * Get current dashboard.
     *
     * @return string
     */
    public function currentDashboard(): string {
        $current = Http::get('type');

        return !empty($current) && array_key_exists($current, $this->getDashboards()) ? $current : 'server';
    }
}
