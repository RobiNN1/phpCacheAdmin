<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Dashboards\RemoteServersTrait;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class APCuDashboard implements DashboardInterface {
    use APCuTrait;
    use RemoteServersTrait;

    public function __construct(private readonly Template $template) {
        $this->initServers(extension_loaded('apcu'));
    }

    public static function check(): bool {
        return extension_loaded('apcu') || self::remoteServers() !== [];
    }

    /**
     * @return array<string, array<int, string>|string>
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
        if (isset($_GET['panels'])) {
            try {
                $this->loadRemote('apcu');
            } catch (DashboardException $e) {
                return Helpers::getPanelsJson(['error' => $e->getMessage()]);
            }

            return Helpers::getPanelsJson($this->getPanelsData());
        }

        // pca-remote.php never returns the values.
        if ((isset($_GET['view']) || isset($_GET['deleteall']) || isset($_GET['delete'])) && $this->isRemote()) {
            return $this->remoteActionError();
        }

        if (isset($_GET['view'], $_GET['key'])) {
            return $this->viewKey();
        }

        if (isset($_GET['deleteall'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return Helpers::alert('Invalid CSRF token.', 'error');
            }

            if (apcu_clear_cache()) {
                return Helpers::alert('Cache has been cleaned.', 'success');
            }
        }

        if (isset($_GET['delete'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return Helpers::alert('Invalid CSRF token.', 'error');
            }

            return Helpers::deleteKey(static fn (string $key): bool => apcu_delete($key));
        }

        return '';
    }

    public function dashboard(): string {
        $this->serversGlobals();

        try {
            $this->loadRemote('apcu', $this->keysFlag());
        } catch (DashboardException $e) {
            return $this->errorAlert($e);
        }

        $this->template->addGlobal('ajax_panels', true);
        $this->template->addGlobal('side', Helpers::panels($this->getPanelsData()));

        $tabs = $this->template->render('components/tabs', ['links' => $this->tabs, 'main' => true,]);

        if ($this->isRemote()) {
            return $tabs.$this->mainDashboard(); // pca-remote.php never returns the values, so there is no key view or form.
        }

        if (isset($_GET['view'], $_GET['key'])) {
            return $tabs.$this->viewKey();
        }

        if (isset($_GET['form'])) {
            return $tabs.$this->form();
        }

        return $tabs.$this->mainDashboard();
    }
}
