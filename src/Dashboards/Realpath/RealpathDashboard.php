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

namespace RobiNN\Pca\Dashboards\Realpath;

use RobiNN\Pca\Dashboards\DashboardInterface;
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
            'key'   => 'realpath',
            'title' => 'Realpath',
        ];
    }

    public function ajax(): string {
        $return = '';

        if (isset($_GET['deleteall'])) {
            clearstatcache(true);

            $return = $this->template->render('components/alert', [
                'message' => 'Cache has been cleaned.',
            ]);
        }

        return $return;
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
