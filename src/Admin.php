<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use RobiNN\Pca\Dashboards\DashboardInterface;

class Admin {
    public const VERSION = '2.1.3';

    /**
     * @var array<string, DashboardInterface>
     */
    public array $dashboards = [];

    public function __construct(Template $template) {
        foreach (Config::get('dashboards', []) as $class) {
            if (is_subclass_of($class, DashboardInterface::class) && $class::check()) {
                $dashboard = new $class($template);
                $info = $dashboard->dashboardInfo();
                $this->dashboards[$info['key']] = $dashboard;
            }
        }
    }

    public function getDashboard(string $dashboard): DashboardInterface {
        return $this->dashboards[$dashboard];
    }

    public function currentDashboard(): string {
        $current = Http::get('dashboard', '');

        return array_key_exists($current, $this->dashboards) ? $current : array_key_first($this->dashboards);
    }
}
