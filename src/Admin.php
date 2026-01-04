<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use RobiNN\Pca\Dashboards\DashboardInterface;

class Admin {
    public const VERSION = '2.4.1';

    private readonly Template $template;

    /**
     * @var array<string, DashboardInterface>
     */
    private array $dashboards = [];

    public function __construct() {
        $this->template = new Template();

        foreach (Config::get('dashboards', []) as $class) {
            if (is_subclass_of($class, DashboardInterface::class) && $class::check()) {
                $dashboard = new $class($this->template);
                $info = $dashboard->dashboardInfo();
                $this->dashboards[$info['key']] = $dashboard;
            }
        }
    }

    private function getDashboard(string $dashboard): DashboardInterface {
        return $this->dashboards[$dashboard];
    }

    private function currentDashboard(): string {
        $current = Http::get('dashboard', '');

        return array_key_exists($current, $this->dashboards) ? $current : array_key_first($this->dashboards);
    }

    public function render(bool $auth): string {
        $nav = array_map(static fn (DashboardInterface $d_dashboard): array => $d_dashboard->dashboardInfo(), $this->dashboards);

        $current = $this->currentDashboard();
        $dashboard = $this->getDashboard($current);
        $info = $dashboard->dashboardInfo();

        $this->template->addGlobal('current', $current);

        if (isset($_GET['ajax'])) {
            return $dashboard->ajax();
        }

        $colors = '';

        if (isset($info['colors'])) {
            foreach ((array) $info['colors'] as $key => $color) {
                $colors .= '--color-primary-'.$key.':'.$color.';';
            }
        }

        return $this->template->render('layout', [
            'colors'     => $colors,
            'site_title' => $info['title'],
            'nav'        => $nav,
            'logout_url' => $auth ? Http::queryString([], ['logout' => 'yes']) : null,
            'version'    => self::VERSION,
            'repo'       => 'https://github.com/RobiNN1/phpCacheAdmin',
            'dashboard'  => $dashboard->dashboard(),
        ]);
    }
}
