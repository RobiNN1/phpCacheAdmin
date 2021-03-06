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

    /**
     * Check if an extension is installed.
     *
     * @return bool
     */
    public function check(): bool {
        return extension_loaded('Zend OPcache');
    }

    /**
     * Get dashboard info.
     *
     * @return array<string, string>
     */
    public function getDashboardInfo(): array {
        return [
            'key'   => 'opcache',
            'title' => 'OPCache',
            'color' => 'sky',
        ];
    }

    /**
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string {
        $return = '';

        if (isset($_GET['deleteall']) && opcache_reset()) {
            $return = $this->template->render('components/alert', [
                'message' => 'Cache has been cleaned.',
            ]);
        }

        if (isset($_GET['delete'])) {
            $return = $this->deleteScript();
        }

        return $return;
    }

    /**
     * Data for info panels.
     *
     * @return array<string, mixed>
     */
    public function info(): array {
        $status = opcache_get_status();
        $directives = opcache_get_configuration()['directives'];

        $stats = $status['opcache_statistics'];

        $total_memory = $directives['opcache.memory_consumption'];
        $memory = $status['memory_usage'];
        $memory_usage = ($memory['used_memory'] + $memory['wasted_memory']) / $total_memory;

        return [
            'panels' => [
                [
                    'title'    => 'Status',
                    'moreinfo' => true,
                    'data'     => [
                        'JIT'          => Helpers::enabledDisabledBadge($this->template, isset($status['jit']) && $status['jit']['enabled']),
                        'Start time'   => Helpers::formatTime($stats['start_time']),
                        'Last restart' => Helpers::formatTime($stats['last_restart_time']),
                        'Cache full'   => Helpers::enabledDisabledBadge($this->template, $status['cache_full'] === false, null, ['No', 'Yes']),
                    ],
                ],
                [
                    'title' => 'Memory',
                    'data'  => [
                        'Total'          => Helpers::formatBytes($total_memory),
                        'Usage'          => round(100 * $memory_usage).'%',
                        'Used'           => Helpers::formatBytes($memory['used_memory']),
                        'Free'           => Helpers::formatBytes($memory['free_memory']),
                        'Wasted'         => Helpers::formatBytes($memory['wasted_memory']),
                        'Current wasted' => round($memory['current_wasted_percentage'], 3).'%',
                    ],
                ],
                [
                    'title' => 'Stats',
                    'data'  => [
                        'Cached scripts'  => $stats['num_cached_scripts'],
                        'Cached keys'     => Helpers::formatNumber($stats['num_cached_keys']),
                        'Max cached keys' => Helpers::formatNumber($stats['max_cached_keys']),
                        'Hits'            => Helpers::formatNumber($stats['hits']),
                        'Misses'          => Helpers::formatNumber($stats['misses']),
                        'Hit rate'        => round($stats['opcache_hit_rate']).'%',
                    ],
                ],
            ],
        ];
    }

    /**
     * Show info panels.
     *
     * @return string
     */
    public function showPanels(): string {
        if (isset($_GET['moreinfo'])) {
            return '';
        }

        return $this->template->render('partials/info', [
            'title'             => 'OPCache',
            'extension_version' => phpversion('Zend OPcache'),
            'info'              => $this->info(),
        ]);
    }

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        $status = opcache_get_status();

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($status);
        } else {
            $return = $this->mainDashboard($status);
        }

        return $return;
    }
}
