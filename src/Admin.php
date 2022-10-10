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
    public const VERSION = '1.3.1';

    /**
     * @var array<string, DashboardInterface>
     */
    private array $dashboards = [];

    /**
     * @param ?Template $template
     */
    public function __construct(?Template $template = null) {
        foreach (Config::get('dashboards') as $class) {
            if (is_subclass_of($class, DashboardInterface::class) && $class::check()) {
                $dashboard = new $class($template);
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
     * @return DashboardInterface
     */
    public function getDashboard(string $dashboard): DashboardInterface {
        return $this->dashboards[$dashboard];
    }

    /**
     * Get current dashboard.
     *
     * @return string
     */
    public function currentDashboard(): string {
        $current = Http::get('type');

        return array_key_exists($current, $this->getDashboards()) ? $current : 'server';
    }

    /**
     * Get git info.
     *
     * @return ?array<string, string>
     */
    public function getGitInfo(): ?array {
        $git_path = __DIR__.'/../.git';
        $head_file = $git_path.'/HEAD';

        if (!is_file($head_file)) {
            return null;
        }

        $head_file_parts = explode('/', file_get_contents($head_file));
        $branch = isset($head_file_parts[2]) ? trim($head_file_parts[2]) : '';
        $branch_file = $git_path.'/refs/heads/'.$branch;

        if (!is_file($branch_file)) {
            return null;
        }

        $commit = trim(file_get_contents($branch_file));

        return [
            'commit'    => $commit,
            'short_sha' => substr($commit, 0, 7),
            'branch'    => $branch,
        ];
    }
}
