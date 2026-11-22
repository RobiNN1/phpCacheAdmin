<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Realpath;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\KeyFilters;
use RobiNN\Pca\Paginator;

trait RealpathTrait {
    use RealpathHealth;

    /**
     * @var array<string, string>
     */
    private array $tabs = [
        'keys'   => 'Keys',
        'health' => 'Health',
    ];

    /**
     * @return array{used: int, total: int, ttl: int, open_basedir: string, entries: array<string, mixed>}
     */
    private function cacheInfo(): array {
        if ($this->remote === null) {
            return [
                'used'         => realpath_cache_size(),
                'total'        => Format::iniSizeToBytes((string) ini_get('realpath_cache_size')),
                'ttl'          => (int) ini_get('realpath_cache_ttl'),
                'open_basedir' => (string) ini_get('open_basedir'),
                'entries'      => realpath_cache_get(),
            ];
        }

        $data = $this->remote->fetched('realpath');
        $ini = (array) ($data['ini'] ?? []);

        return [
            'used'         => (int) ($data['size'] ?? 0),
            'total'        => Format::iniSizeToBytes((string) ($ini['realpath_cache_size'] ?? '')),
            'ttl'          => (int) ($ini['realpath_cache_ttl'] ?? 0),
            'open_basedir' => (string) ($ini['open_basedir'] ?? ''),
            'entries'      => (array) ($data['entries'] ?? []),
        ];
    }

    private function mainDashboard(): string {
        $tab = Http::get('tab', '');
        $tab = array_key_exists($tab, $this->tabs) ? $tab : array_key_first($this->tabs);

        if ($tab === 'health') {
            return $this->template->render('partials/health', ['checks' => $this->getHealthChecks()]);
        }

        $keys = $this->getAllKeys();
        $filters = $this->keyFilters();

        if ($filters->error !== null) {
            Helpers::alert($filters->error, 'error');
        }

        $paginator = new Paginator($keys, [['pp', ...$filters->params()], ['p' => '']]);

        return $this->template->render('dashboards/realpath', [
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($this->all_keys),
            'paginator'   => $paginator->render(),
            'key_filters' => $filters->view(count($keys), ['type' => 'Kind'], null),
        ]);
    }

    private function panels(): string {
        $info = $this->cacheInfo();
        $total_memory = $info['total'];
        $memory_used = $info['used'];
        $memory_usage = $total_memory > 0 ? round(($memory_used / $total_memory) * 100, 2) : 0;

        $panels = [
            [
                'title' => 'Realpath info',
                'data'  => [
                    'Total' => Format::bytes($total_memory, 1),
                    ['Used', Format::bytes($memory_used).' ('.$memory_usage.'%)', $memory_usage],
                ],
            ],
            [
                'title' => 'Keys',
                'data'  => [
                    'TTL'    => $info['ttl'],
                    'Cached' => Format::number(count($this->all_keys)),
                ],
            ],
        ];

        return Helpers::panels($panels);
    }

    private function keyFilters(): KeyFilters {
        return new KeyFilters(['type'], ['dir' => 'Directories', 'file' => 'Files']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAllKeys(): array {
        $keys = [];
        $filters = $this->keyFilters();
        $matcher = $filters->matcher();

        $this->template->addGlobal('search_value', $filters->values['s']);

        foreach ($this->all_keys as $key_name => $key_data) {
            if (($matcher === null || $matcher((string) $key_name)) && $filters->matches(['type' => $key_data['is_dir'] ? 'dir' : 'file'])) {
                $keys[] = [
                    'key'  => $key_name,
                    'info' => [
                        'title'    => $key_name,
                        'realpath' => $key_data['realpath'],
                        'is_dir'   => $key_data['is_dir'] ? 'true' : 'false',
                        'ttl'      => $key_data['expires'] - time(),
                    ],
                ];
            }
        }

        return Helpers::sortKeys($keys);
    }
}
