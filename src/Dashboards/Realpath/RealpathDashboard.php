<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Realpath;

use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Template;

class RealpathDashboard implements DashboardInterface {
    use RealpathTrait;

    private Template $template;

    public function __construct(Template $template) {
        $this->template = $template;
    }

    public static function check(): bool {
        return true; // No extension required.
    }

    /**
     * @return array<string, string>
     */
    public function dashboardInfo(): array {
        return [
            'key'    => 'realpath',
            'title'  => 'Realpath',
            'colors' => [
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
        if (isset($_GET['deleteall'])) {
            clearstatcache(true);

            return Helpers::alert($this->template, 'Cache has been cleaned.', 'success');
        }

        return '';
    }

    public function dashboard(): string {
        $all_keys = realpath_cache_get();
        $keys = $this->getAllKeys($all_keys);

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/realpath', [
            'panels'    => $this->panels($all_keys),
            'keys'      => $paginator->getPaginated(),
            'all_keys'  => count($all_keys),
            'paginator' => $paginator->render(),
        ]);
    }
}
