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
use RobiNN\Pca\Format;
use RobiNN\Pca\Template;

class APCuDashboard implements DashboardInterface {
    use APCuTrait;

    private Template $template;

    public function __construct(Template $template) {
        $this->template = $template;
    }

    public static function check(): bool {
        return extension_loaded('apcu');
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function getDashboardInfo(): array {
        return [
            'key'    => 'apcu',
            'title'  => 'APCu',
            'colors' => [
                100 => '#dbeafe',
                200 => '#bfdbfe',
                300 => '#93c5fd',
                500 => '#3b82f6',
                600 => '#2563eb',
                700 => '#1d4ed8',
                900 => '#1e3a8a',
            ],
        ];
    }

    public function ajax(): string {
        $return = '';

        if (isset($_GET['deleteall']) && apcu_clear_cache()) {
            $return = $this->template->render('components/alert', [
                'message' => 'Cache has been cleaned.',
            ]);
        }

        if (isset($_GET['delete'])) {
            $return = $this->deleteKey();
        }

        return $return;
    }

    public function infoPanels(): string {
        if (isset($_GET['moreinfo']) || isset($_GET['form']) || isset($_GET['view'], $_GET['key'])) {
            return '';
        }

        $info = apcu_cache_info();
        $memory_info = apcu_sma_info();

        $total_memory = $memory_info['num_seg'] * $memory_info['seg_size'];
        $memory_used = ($memory_info['num_seg'] * $memory_info['seg_size']) - $memory_info['avail_mem'];

        $hit_rate = (int) $info['num_hits'] !== 0 ? $info['num_hits'] / ($info['num_hits'] + $info['num_misses']) : 0;

        return $this->template->render('partials/info', [
            'title'             => 'PHP <span class="font-semibold">APCu</span> extension',
            'extension_version' => phpversion('apcu'),
            'info'              => [
                'panels' => [
                    [
                        'title'    => 'Status',
                        'moreinfo' => true,
                        'data'     => [
                            'Start time'       => Format::time($info['start_time']),
                            'Cache full count' => $info['expunges'],
                        ],
                    ],
                    [
                        'title' => 'Memory',
                        'data'  => [
                            'Total' => Format::bytes((int) $total_memory),
                            'Used'  => Format::bytes((int) $memory_used),
                            'Free'  => Format::bytes((int) $memory_info['avail_mem']),
                        ],
                    ],
                    [
                        'title' => 'Stats',
                        'data'  => [
                            'Cached scripts' => $info['num_entries'],
                            'Hits'           => Format::number((int) $info['num_hits']),
                            'Misses'         => Format::number((int) $info['num_misses']),
                            'Hit rate'       => round($hit_rate * 100).'%',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function dashboard(): string {
        $info = apcu_cache_info();

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($info);
        } elseif (isset($_GET['view'], $_GET['key'])) {
            $return = $this->viewKey();
        } elseif (isset($_GET['form'])) {
            $return = $this->form();
        } else {
            $return = $this->mainDashboard($info);
        }

        return $return;
    }
}
