<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Realpath;

use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Dashboards\RemoteServersTrait;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class RealpathDashboard implements DashboardInterface {
    use RealpathTrait;
    use RemoteServersTrait;

    /**
     * @var array<string, mixed>
     */
    private array $all_keys = [];

    public function __construct(private readonly Template $template) {
        $this->initServers(true);
    }

    public static function check(): bool {
        return true; // No extension required.
    }

    /**
     * @return array<string, array<int, string>|bool|string>
     */
    public function dashboardInfo(): array {
        return [
            'key'      => 'realpath',
            'title'    => 'Realpath',
            'readonly' => false,
            'colors'   => [
                50  => '#f4f6fa',
                100 => '#e6ebf3',
                200 => '#d2dbeb',
                300 => '#b3c3dd',
                400 => '#8fa5cb',
                500 => '#748abd',
                600 => '#6172af',
                700 => '#4f5b93',
                800 => '#4a5283',
                900 => '#3f4669',
                950 => '#292d42',
            ],
        ];
    }

    public function ajax(): string {
        if (isset($_GET['deleteall']) && $this->isRemote()) {
            return $this->remoteActionError();
        }

        if (isset($_GET['deleteall'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return Helpers::alert('Invalid CSRF token.', 'error');
            }

            clearstatcache(true);

            return Helpers::alert('Cache has been cleaned.', 'success');
        }

        return '';
    }

    public function dashboard(): string {
        $this->serversGlobals();

        try {
            $this->loadRemote('realpath');
        } catch (DashboardException $e) {
            return $this->errorAlert($e);
        }

        $this->all_keys = $this->cacheInfo()['entries'];

        $this->template->addGlobal('side', $this->panels());

        $tabs = $this->template->render('components/tabs', ['links' => $this->tabs, 'main' => true]);

        return $tabs.$this->mainDashboard();
    }
}
