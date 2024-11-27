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

    /**
     * @var array<string, mixed>
     * @noinspection PhpPrivateFieldCanBeLocalVariableInspection
     */
    private array $all_keys = [];

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
        $this->all_keys = realpath_cache_get();

        $this->template->addGlobal('side', $this->panels());

        $keys = $this->getAllKeys();
        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/realpath', [
            'keys'      => $paginator->getPaginated(),
            'all_keys'  => count($this->all_keys),
            'paginator' => $paginator->render(),
        ]);
    }
}
