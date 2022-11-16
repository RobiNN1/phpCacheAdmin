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
use RobiNN\Pca\Format;
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
            $return = $this->deleteScript();
        }

        return $return;
    }

    /**
     * @return array<int, mixed>
     */
    private function panels(): array {
        $status = opcache_get_status();
        $configuration = opcache_get_configuration();

        $stats = $status['opcache_statistics'];

        $total_memory = $configuration['directives']['opcache.memory_consumption'];
        $memory = $status['memory_usage'];
        $memory_usage = ($memory['used_memory'] + $memory['wasted_memory']) / $total_memory;

        return [
            [
                'title'    => 'Status',
                'moreinfo' => true,
                'data'     => [
                    'JIT'          => isset($status['jit']) && $status['jit']['enabled'] ? 'Enabled' : 'Disabled',
                    'Start time'   => Format::time($stats['start_time']),
                    'Last restart' => Format::time($stats['last_restart_time']),
                    'Cache full'   => $status['cache_full'] ? 'Yes' : 'No',
                ],
            ],
            [
                'title' => 'Memory',
                'data'  => [
                    'Total'          => Format::bytes($total_memory),
                    'Usage'          => round(100 * $memory_usage).'%',
                    'Used'           => Format::bytes($memory['used_memory']),
                    'Free'           => Format::bytes($memory['free_memory']),
                    'Wasted'         => Format::bytes($memory['wasted_memory']),
                    'Current wasted' => round($memory['current_wasted_percentage'], 3).'%',
                ],
            ],
            [
                'title' => 'Stats',
                'data'  => [
                    'Cached scripts'  => $stats['num_cached_scripts'],
                    'Cached keys'     => Format::number($stats['num_cached_keys']),
                    'Max cached keys' => Format::number($stats['max_cached_keys']),
                    'Hits'            => Format::number($stats['hits']),
                    'Misses'          => Format::number($stats['misses']),
                    'Hit rate'        => round($stats['opcache_hit_rate']).'%',
                ],
            ],
        ];
    }

    public function infoPanels(): string {
        // Hide panels on more info page.
        if (isset($_GET['moreinfo'])) {
            return '';
        }

        return $this->template->render('partials/info', [
            'title'             => 'PHP <span class="font-semibold">OPCache</span> extension',
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
