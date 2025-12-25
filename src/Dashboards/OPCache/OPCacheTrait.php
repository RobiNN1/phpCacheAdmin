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
    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(): array {
        $status = opcache_get_status(false);
        $configuration = opcache_get_configuration();

        $stats = $status['opcache_statistics'];

        $memory = $status['memory_usage'];
        $total_memory = $configuration['directives']['opcache.memory_consumption'];
        $memory_usage = round((($memory['used_memory'] + $memory['wasted_memory']) / $total_memory) * 100, 2);
        $memory_wasted = round($memory['current_wasted_percentage'], 2);

        $interned_strings = $status['interned_strings_usage'];
        $interned_usage = round(($interned_strings['used_memory'] / $interned_strings['buffer_size']) * 100, 2);

        $used_scripts = round(($stats['num_cached_scripts'] / (int) ini_get('opcache.max_accelerated_files')) * 100);
        $used_keys = round(($stats['num_cached_keys'] / $stats['max_cached_keys']) * 100);
        $hit_rate = round($stats['opcache_hit_rate'], 2);

        $jit_enabled = isset($status['jit']['enabled']) && $status['jit']['buffer_size'] > 0;
        $jit_info = [];

        if ($jit_enabled) {
            $jit = $status['jit'];
            $jit_used = $jit['buffer_size'] - $jit['buffer_free'];
            $jit_usage = round(($jit_used / $jit['buffer_size']) * 100, 2);

            $jit_info = [
                'title' => 'JIT',
                'data'  => [
                    'Buffer size'        => Format::bytes($jit['buffer_size']),
                    ['Used', Format::bytes($jit_used).' ('.$jit_usage.'%)', $jit_usage],
                    'Free'               => Format::bytes($jit['buffer_free']),
                    'Optimization level' => $jit['opt_level'],
                ],
            ];
        }

        return [
            [
                'title'    => 'OPCache extension v'.phpversion('Zend OPcache'),
                'moreinfo' => true,
                'data'     => [
                    'JIT'                 => $jit_enabled ? 'Enabled' : 'Disabled',
                    'Start time'          => Format::time($stats['start_time']),
                    'Uptime'              => Format::seconds(time() - $stats['start_time']),
                    'Last restart'        => Format::time($stats['last_restart_time']),
                    'Cache full'          => $status['cache_full'] ? 'Yes' : 'No',
                    'Restart pending'     => $status['restart_pending'] ? 'Yes' : 'No',
                    'Restart in progress' => $status['restart_in_progress'] ? 'Yes' : 'No',
                ],
            ],
            [
                'title' => 'Memory',
                'data'  => [
                    'Total' => Format::bytes($total_memory, 0),
                    ['Used', Format::bytes($memory['used_memory']).' ('.$memory_usage.'%)', $memory_usage],
                    'Free'  => Format::bytes($memory['free_memory']),
                    ['Wasted', Format::bytes($memory['wasted_memory']).' ('.$memory_wasted.'%)', $memory_wasted],
                ],
            ],
            [
                'title' => 'Stats',
                'data'  => [
                    'Max accelerated_files' => Format::number($configuration['directives']['opcache.max_accelerated_files']),
                    ['Cached scripts', Format::number($stats['num_cached_scripts']).' ('.$used_scripts.'%)', $used_scripts, 'higher'],
                    ['Cached keys', Format::number($stats['num_cached_keys']).' ('.$used_keys.'%)', $used_keys, 'higher'],
                    'Max cached keys'       => Format::number($stats['max_cached_keys']),
                    ['Hits / Misses', Format::number($stats['hits']).' / '.Format::number($stats['misses']).' (Rate '.$hit_rate.'%)', $hit_rate, 'higher'],
                ],
            ],
            $jit_info,
            [
                'title' => 'Interned strings usage',
                'data'  => [
                    'Buffer size' => Format::bytes($interned_strings['buffer_size']),
                    ['Used', Format::bytes($interned_strings['used_memory']).' ('.$interned_usage.'%)', $interned_usage],
                    'Free'        => Format::bytes($interned_strings['free_memory']),
                    'Strings'     => Format::number($interned_strings['number_of_strings']),
                ],
            ],
        ];
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
        $cached_scripts = [];
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

                if (stripos($script['full_path'], $search) !== false) {
                    $cached_scripts[] = [
                        'key'  => $script['full_path'],
                        'info' => [
                            'title'              => $full_path,
                            'number_hits'        => $script['hits'],
                            'bytes_memory'       => $script['memory_consumption'],
                            'timediff_last_used' => $script['last_used_timestamp'],
                            'time_created'       => $script['timestamp'] ?? 0,
                        ],
                    ];
                }
            }
        }

        unset($status);

        return Helpers::sortKeys($this->template, $cached_scripts);
    }

    private function mainDashboard(): string {
        $cached_scripts = $this->getCachedScripts();
        $paginator = new Paginator($this->template, $cached_scripts, [['ignore', 'pp', 's'], ['p' => '']]);
        $status = opcache_get_status(false);

        return $this->template->render('dashboards/opcache', [
            'cached_scripts' => $paginator->getPaginated(),
            'all_keys'       => $status['opcache_statistics']['num_cached_scripts'],
            'paginator'      => $paginator->render(),
            'is_ignored'     => isset($_GET['ignore']) && $_GET['ignore'] === 'yes',
        ]);
    }
}
