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
                100 => '#dbeafe',
                200 => '#bfdbfe',
                300 => '#93c5fd',
                500 => '#3b82f6',
                600 => '#2563eb',
                700 => '#1d4ed8',
                900 => '#1e3a8a',
            ],
        ];
    }

    public function ajax(): string {
        $return = '';

        if (isset($_GET['deleteall']) && apcu_clear_cache()) {
            $return = $this->template->render('components/alert', [
                'message' => 'Cache has been cleaned.',
            ]);
        }

        if (isset($_GET['delete'])) {
            $return = Helpers::deleteKey($this->template, static fn (string $key): bool => apcu_delete($key), true);
        }

        return $return;
    }

    public function dashboard(): string {
        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo();
        } elseif (isset($_GET['view'], $_GET['key'])) {
            $return = $this->viewKey();
        } elseif (isset($_GET['form'])) {
            $return = $this->form();
        } else {
            $return = $this->mainDashboard();
        }

        return $return;
    }
}
