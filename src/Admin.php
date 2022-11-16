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
    public const VERSION = '1.4.0';

    /**
     * @var array<string, DashboardInterface>
     */
    private array $dashboards = [];

    public function __construct(Template $template) {
        foreach (Config::get('dashboards') as $class) {
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
        $current = Http::get('type');
        $dashboards = $this->getDashboards();

        return array_key_exists($current, $dashboards) ? $current : array_key_first($dashboards);
    }

    /**
     * @param string $git_path Path to the .git folder.
     *
     * @return ?array<string, string>
     */
    public function getGitInfo(string $git_path): ?array {
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
