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

namespace RobiNN\Pca\Dashboards\APCu;

use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

class APCuDashboard implements DashboardInterface {
    use APCuTrait;

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
        return extension_loaded('apcu');
    }

    /**
     * Get dashboard info.
     *
     * @return array<string, string>
     */
    public function getDashboardInfo(): array {
        return [
            'key'   => 'apcu',
            'title' => 'APCu',
            'color' => 'slate',
        ];
    }

    /**
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string {
        $return = '';

        if (isset($_GET['deleteall']) && apcu_clear_cache()) {
            $return = $this->template->render('components/alert', [
                'message' => 'Cache has been cleaned.',
            ]);
        }

        if (isset($_GET['delete'])) {
            $return = $this->deletekey();
        }

        return $return;
    }

    /**
     * Data for info panels.
     *
     * @return array<string, mixed>
     */
    public function info(): array {
        $info = apcu_cache_info();

        $memory_info = apcu_sma_info();

        $total_memory = $memory_info['num_seg'] * $memory_info['seg_size'];
        $memory_used = ($memory_info['num_seg'] * $memory_info['seg_size']) - $memory_info['avail_mem'];

        $hit_rate = (int) $info['num_hits'] !== 0 ? $info['num_hits'] / ($info['num_hits'] + $info['num_misses']) : 0;

        return [
            'panels' => [
                [
                    'title'    => 'Status',
                    'moreinfo' => true,
                    'data'     => [
                        'Start time'       => Helpers::formatTime($info['start_time']),
                        'Cache full count' => $info['expunges'],
                    ],
                ],
                [
                    'title' => 'Memory',
                    'data'  => [
                        'Total' => Helpers::formatBytes((int) $total_memory),
                        'Used'  => Helpers::formatBytes((int) $memory_used),
                        'Free'  => Helpers::formatBytes((int) $memory_info['avail_mem']),
                    ],
                ],
                [
                    'title' => 'Stats',
                    'data'  => [
                        'Cached scripts' => $info['num_entries'],
                        'Hits'           => Helpers::formatNumber((int) $info['num_hits']),
                        'Misses'         => Helpers::formatNumber((int) $info['num_misses']),
                        'Hit rate'       => round($hit_rate * 100).'%',
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
        if (isset($_GET['moreinfo']) || isset($_GET['form']) || isset($_GET['view'], $_GET['key'])) {
            return '';
        }

        return $this->template->render('partials/info', [
            'title'             => 'APCu',
            'extension_version' => phpversion('apcu'),
            'info'              => $this->info(),
        ]);
    }

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        $info = apcu_cache_info();

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($info);
        } elseif (isset($_GET['view']) && !empty($_GET['key'])) {
            $return = $this->viewKey();
        } elseif (isset($_GET['form'])) {
            $return = $this->form();
        } else {
            $return = $this->mainDashboard($info);
        }

        return $return;
    }
}
