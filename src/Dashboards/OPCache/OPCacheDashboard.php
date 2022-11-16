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

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

class OPCacheDashboard implements DashboardInterface {
    use OPCacheTrait;

    private Template $template;

    public function __construct(Template $template) {
        $this->template = $template;
    }

    public static function check(): bool {
        return extension_loaded('Zend OPcache');
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function dashboardInfo(): array {
        return [
            'key'    => 'opcache',
            'title'  => 'OPCache',
            'colors' => [
                100 => '#e0f2fe',
                200 => '#bae6fd',
                300 => '#7dd3fc',
                500 => '#0ea5e9',
                600 => '#0284c7',
                700 => '#0369a1',
                900 => '#0c4a6e',
            ],
        ];
    }

    public function ajax(): string {
        $return = '';

        if (isset($_GET['deleteall']) && opcache_reset()) {
            $return = $this->template->render('components/alert', [
                'message' => 'Cache has been cleaned.',
            ]);
        }

        if (isset($_GET['delete'])) {
            $return = Helpers::deleteKey($this->template, static fn (string $key): bool => opcache_invalidate($key, true));
        }

        return $return;
    }

    public function infoPanels(): string {
        // Hide panels on more info page.
        if (isset($_GET['moreinfo'])) {
            return '';
        }

        return $this->template->render('partials/info', [
            'title'             => 'PHP OPCache extension',
            'extension_version' => phpversion('Zend OPcache'),
            'info'              => ['panels' => $this->panels()],
        ]);
    }

    public function dashboard(): string {
        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo();
        } else {
            $return = $this->mainDashboard();
        }

        return $return;
    }
}
