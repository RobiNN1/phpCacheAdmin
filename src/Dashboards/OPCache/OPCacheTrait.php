<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait OPCacheTrait {
    private function panels(): string {
        $status = opcache_get_status(false);
        $configuration = opcache_get_configuration();

        $stats = $status['opcache_statistics'];

        $total_memory = $configuration['directives']['opcache.memory_consumption'];
        $memory = $status['memory_usage'];
        $memory_usage = ($memory['used_memory'] + $memory['wasted_memory']) / $total_memory;
        $memory_usage_percentage = round($memory_usage * 100, 2);

        $used_keys_percentage = round(($stats['num_cached_keys'] / $stats['max_cached_keys']) * 100);

        $panels = [
            [
                'title'    => 'PHP OPCache extension <span>v'.phpversion('Zend OPcache').'</span>',
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
                    'Total'  => Format::bytes($total_memory, 0),
                    'Used'   => Format::bytes($memory['used_memory']).' ('.$memory_usage_percentage.'%)',
                    'Free'   => Format::bytes($memory['free_memory']),
                    'Wasted' => Format::bytes($memory['wasted_memory']).' ('.round($memory['current_wasted_percentage'], 2).'%)',
                ],
            ],
            [
                'title' => 'Stats',
                'data'  => [
                    'Cached scripts'  => Format::number($stats['num_cached_scripts']),
                    'Cached keys'     => Format::number($stats['num_cached_keys']).' ('.$used_keys_percentage.'%)',
                    'Max cached keys' => Format::number($stats['max_cached_keys']),
                    'Hits / Misses'   => Format::number($stats['hits']).' / '.Format::number($stats['misses']).
                        ' (Rate '.round($stats['opcache_hit_rate'], 2).'%)',
                ],
            ],
        ];

        return $this->template->render('partials/info', ['panels' => $panels]);
    }

    private function moreInfo(): string {
        $status = (array) opcache_get_status(false);

        $configuration = opcache_get_configuration();
        $status['ini_config'] = $configuration['directives'];

        return $this->template->render('partials/info_table', [
            'panel_title' => 'OPCache Info',
            'array'       => Helpers::convertTypesToString($status),
        ]);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function getCachedScripts(): array {
        static $cached_scripts = [];
        $search = Http::get('s', '');

        $this->template->addGlobal('search_value', $search);

        $status = opcache_get_status();

        if (isset($status['scripts'])) {
            foreach ($status['scripts'] as $script) {
                $full_path = str_replace('\\', '/', $script['full_path']);
                $pca_root = $_SERVER['DOCUMENT_ROOT'].str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);

                if (
                    (isset($_GET['ignore']) && $_GET['ignore'] === 'yes') &&
                    str_starts_with(strtr($full_path, ['phar://' => '']), $pca_root)
                ) {
                    continue;
                }

                if ($search === '' || stripos($script['full_path'], $search) !== false) {
                    $cached_scripts[] = [
                        'key'   => $script['full_path'],
                        'items' => [
                            'title'          => $full_path,
                            'number_hits'    => $script['hits'],
                            'bytes_memory'   => $script['memory_consumption'],
                            'time_last_used' => $script['last_used_timestamp'],
                            'time_created'   => $script['timestamp'] ?? 0,
                        ],
                    ];
                }
            }
        }

        return $cached_scripts;
    }

    private function mainDashboard(): string {
        $cached_scripts = $this->getCachedScripts();
        $paginator = new Paginator($this->template, $cached_scripts, [['ignore', 'pp'], ['p' => '']]);
        $is_ignored = isset($_GET['ignore']) && $_GET['ignore'] === 'yes';
        $status = opcache_get_status(false);

        return $this->template->render('dashboards/opcache', [
            'panels'         => $this->panels(),
            'cached_scripts' => $paginator->getPaginated(),
            'all_files'      => $status['opcache_statistics']['num_cached_scripts'],
            'paginator'      => $paginator->render(),
            'ignore_url'     => Http::queryString(['pp', 'p'], ['ignore' => $is_ignored ? 'no' : 'yes']),
            'is_ignored'     => $is_ignored,
        ]);
    }
}
