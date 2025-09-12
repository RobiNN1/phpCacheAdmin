<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

class OPCacheDashboard implements DashboardInterface {
    use OPCacheTrait;

    public function __construct(private readonly Template $template) {
    }

    public static function check(): bool {
        return extension_loaded('Zend OPcache') && ini_get('opcache.restrict_api') !== false;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function dashboardInfo(): array {
        return [
            'key'    => 'opcache',
            'title'  => 'OPCache',
            'colors' => [
                50  => '#f0f9ff',
                100 => '#e0f2fe',
                200 => '#bae6fd',
                300 => '#7dd3fc',
                400 => '#38bdf8',
                500 => '#0ea5e9',
                600 => '#0284c7',
                700 => '#0369a1',
                800 => '#075985',
                900 => '#0c4a6e',
                950 => '#082f49',
            ],
        ];
    }

    public function ajax(): string {
        if (isset($_GET['panels'])) {
            return Helpers::getPanelsJson($this->getPanelsData());
        }

        if (isset($_GET['deleteall']) && opcache_reset()) {
            return Helpers::alert($this->template, 'Cache has been cleaned.', 'success');
        }

        if (isset($_GET['delete'])) {
            return Helpers::deleteKey($this->template, static fn (string $key): bool => opcache_invalidate($key, true));
        }

        return '';
    }

    public function dashboard(): string {
        $this->template->addGlobal('ajax_panels', true);
        $this->template->addGlobal('side', $this->template->render('partials/info', ['panels' => $this->getPanelsData()]));

        if (isset($_GET['moreinfo'])) {
            return $this->moreInfo();
        }

        return $this->mainDashboard();
    }
}
