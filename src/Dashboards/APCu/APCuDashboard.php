<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

class APCuDashboard implements DashboardInterface {
    use APCuTrait;

    private Template $template;

    public function __construct(Template $template) {
        $this->template = $template;
    }

    public static function check(): bool {
        return extension_loaded('apcu');
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function dashboardInfo(): array {
        return [
            'key'    => 'apcu',
            'title'  => 'APCu',
            'colors' => [
                50  => '#eff6ff',
                100 => '#dbeafe',
                200 => '#bfdbfe',
                300 => '#93c5fd',
                400 => '#60a5fa',
                500 => '#3b82f6',
                600 => '#2563eb',
                700 => '#1d4ed8',
                800 => '#1e40af',
                900 => '#1e3a8a',
                950 => '#172554',
            ],
        ];
    }

    public function ajax(): string {
        if (isset($_GET['deleteall']) && apcu_clear_cache()) {
            return Helpers::alert($this->template, 'Cache has been cleaned.', 'success');
        }

        if (isset($_GET['delete'])) {
            return Helpers::deleteKey($this->template, static fn (string $key): bool => apcu_delete($key), true);
        }

        return '';
    }

    public function dashboard(): string {
        if (isset($_GET['moreinfo'])) {
            return $this->moreInfo();
        }

        if (isset($_GET['view'], $_GET['key'])) {
            return $this->viewKey();
        }

        if (isset($_GET['form'])) {
            return $this->form();
        }

        return $this->mainDashboard();

    }
}
