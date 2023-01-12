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

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait OPCacheTrait {
    /**
     * @return array<int, mixed>
     */
    private function panels(): array {
        $status = opcache_get_status(false);
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
                    'Usage'          => round(100 * $memory_usage, 3).'%',
                    'Used'           => Format::bytes($memory['used_memory']),
                    'Free'           => Format::bytes($memory['free_memory']),
                    'Wasted'         => Format::bytes($memory['wasted_memory']),
                    'Current wasted' => round($memory['current_wasted_percentage'], 3).'%',
                ],
            ],
            [
                'title' => 'Stats',
                'data'  => [
                    'Cached scripts'  => Format::number($stats['num_cached_scripts']),
                    'Cached keys'     => Format::number($stats['num_cached_keys']),
                    'Max cached keys' => Format::number($stats['max_cached_keys']),
                    'Hits'            => Format::number($stats['hits']),
                    'Misses'          => Format::number($stats['misses']),
                    'Hit rate'        => round($stats['opcache_hit_rate'], 3).'%',
                ],
            ],
        ];
    }

    private function moreInfo(): string {
        $status = opcache_get_status(false);

        $configuration = opcache_get_configuration();
        $status['ini_config'] = $configuration['directives'];

        return $this->template->render('partials/info_table', [
            'panel_title' => 'OPCache Info',
            'array'       => Helpers::convertBoolToString($status),
        ]);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function getCachedScripts(): array {
        static $cached_scripts = [];

        $status = opcache_get_status();

        if (isset($status['scripts'])) {
            foreach ($status['scripts'] as $script) {
                $full_path = str_replace('\\', '/', $script['full_path']);
                $name = explode('/', $full_path);
                $script_name = $name[array_key_last($name)];

                $pca_root = $_SERVER['DOCUMENT_ROOT'].str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);

                if (
                    (isset($_GET['ignore']) && $_GET['ignore'] === 'yes') &&
                    Helpers::str_starts_with(strtr($full_path, ['phar://' => '']), $pca_root)
                ) {
                    continue;
                }

                $cached_scripts[] = [
                    'key'   => $script['full_path'],
                    'items' => [
                        'title'     => [
                            'title'      => $script_name,
                            'title_attr' => $full_path,
                        ],
                        'hits'      => Format::number($script['hits']),
                        'memory'    => Format::bytes($script['memory_consumption']),
                        'last_used' => Format::time($script['last_used_timestamp']),
                        'created'   => Format::time($script['timestamp']),
                    ],
                ];
            }
        }

        return $cached_scripts;
    }

    private function mainDashboard(): string {
        $cached_scripts = $this->getCachedScripts();
        $paginator = new Paginator($this->template, $cached_scripts, [['ignore', 'pp'], ['p' => '']]);
        $is_ignored = isset($_GET['ignore']) && $_GET['ignore'] === 'yes';

        return $this->template->render('dashboards/opcache', [
            'cached_scripts' => $paginator->getPaginated(),
            'all_files'      => count($cached_scripts),
            'paginator'      => $paginator->render(),
            'ignore_url'     => Http::queryString(['pp', 'p'], ['ignore' => $is_ignored ? 'no' : 'yes']),
            'is_ignored'     => $is_ignored,
        ]);
    }
}
