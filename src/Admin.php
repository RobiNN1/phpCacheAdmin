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
    public const VERSION = '1.5.1';

    /**
     * @var array<string, DashboardInterface>
     */
    private array $dashboards = [];

    public function __construct(Template $template) {
        foreach (Config::get('dashboards', []) as $class) {
            if (is_subclass_of($class, DashboardInterface::class) && $class::check()) {
                $dashboard = new $class($template);
                $info = $dashboard->dashboardInfo();
                $this->dashboards[$info['key']] = $dashboard;
            }
        }
    }

    /**
     * @return array<string, DashboardInterface>
     */
    public function getDashboards(): array {
        return $this->dashboards;
    }

    public function getDashboard(string $dashboard): DashboardInterface {
        return $this->dashboards[$dashboard];
    }

    public function currentDashboard(): string {
        $current = Http::get('type', '');
        $dashboards = $this->getDashboards();

        return array_key_exists($current, $dashboards) ? $current : array_key_first($dashboards);
    }
}
