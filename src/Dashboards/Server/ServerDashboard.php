<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Server;

use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Template;

class ServerDashboard implements DashboardInterface {
    use ServerTrait;

    public function __construct(private readonly Template $template) {
    }

    public static function check(): bool {
        return true; // No extension required.
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function dashboardInfo(): array {
        return [
            'key'   => 'server',
            'title' => 'Server',
        ];
    }

    public function ajax(): string {
        return '';
    }

    public function dashboard(): string {
        if (isset($_GET['moreinfo'])) {
            return $this->phpInfo();
        }

        return $this->template->render('dashboards/server', [
            'panels'     => $this->panels(),
            'extensions' => get_loaded_extensions(),
        ]);
    }
}
