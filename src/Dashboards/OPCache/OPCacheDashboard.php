<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Dashboards\RemoteServersTrait;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class OPCacheDashboard implements DashboardInterface {
    use OPCacheTrait;
    use RemoteServersTrait;

    public function __construct(private readonly Template $template) {
        $this->initServers(extension_loaded('Zend OPcache'));
    }

    public static function check(): bool {
        return extension_loaded('Zend OPcache') || self::remoteServers() !== [];
    }

    /**
     * @return array<string, array<int, string>|bool|string>
     */
    public function dashboardInfo(): array {
        return [
            'key'      => 'opcache',
            'title'    => 'OPCache',
            'readonly' => false,
            'colors'   => [
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
            try {
                $this->loadRemote('opcache');
            } catch (DashboardException $e) {
                return Helpers::getPanelsJson(['error' => $e->getMessage()]);
            }

            return Helpers::getPanelsJson($this->getPanelsData());
        }

        if ((isset($_GET['deleteall']) || isset($_GET['delete'])) && $this->isRemote()) {
            return $this->remoteActionError();
        }

        if (isset($_GET['deleteall'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return Helpers::alert('Invalid CSRF token.', 'error');
            }

            if (opcache_reset()) {
                return Helpers::alert('Cache has been cleaned.', 'success');
            }
        }

        if (isset($_GET['delete'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return Helpers::alert('Invalid CSRF token.', 'error');
            }

            return Helpers::deleteKey(static fn (string $key): bool => opcache_invalidate($key, true));
        }

        return '';
    }

    public function dashboard(): string {
        $this->serversGlobals();

        try {
            $this->loadRemote('opcache', $this->scriptsFlag());

            // Not in the constructor, all dashboards are constructed on every page, and this may be a request to another server.
            if (!$this->preloadIsConfigured()) {
                unset($this->tabs['preload']);
            }

            if ($this->isRemote()) {
                unset($this->tabs['warmup']); // It compiles files of this server.
            }

            // A second request only when a removed tab made the scripts the current one.
            $this->loadRemote('opcache', $this->scriptsFlag());
        } catch (DashboardException $e) {
            return $this->errorAlert($e);
        }

        $this->template->addGlobal('ajax_panels', true);
        $this->template->addGlobal('side', Helpers::panels($this->getPanelsData()));

        $tabs = $this->template->render('components/tabs', ['links' => $this->tabs, 'main' => true,]);

        return $tabs.$this->mainDashboard();
    }
}
