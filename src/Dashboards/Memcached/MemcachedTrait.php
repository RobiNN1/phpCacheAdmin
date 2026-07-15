<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use PDO;
use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait MemcachedTrait {
    use MemcachedPanels;
    use MemcachedHealth;
    use MemcachedAnalysis;
    use MemcachedSlabs;
    use MemcachedKeyView;
    use MemcachedKeysList;
    use MemcachedConsole;

    /**
     * @var array<string, string>
     */
    private array $tabs = [
        'keys'           => 'Keys',
        'analysis'       => 'Analysis',
        'commands_stats' => 'Commands Stats',
        'slabs'          => 'Slabs',
        'items'          => 'Items',
        'metrics'        => 'Metrics',
        'console'        => 'Console',
        'moreinfo'       => 'More info',
    ];

    /**
     * @throws MemcachedException
     */
    private function deleteAllKeys(): string {
        if ($this->memcached->flush()) {
            return Helpers::alert('All keys have been removed.', 'success');
        }

        return Helpers::alert('An error occurred while deleting all keys.', 'error');
    }

    /**
     * @return array<string, mixed>
     */
    private function moreinfoTab(): array {
        try {
            $info = $this->memcached->getServerStats();
            $info += ['settings' => $this->memcached->getServerStats('settings')];

            $server = $this->servers[$this->current_server];
            if (isset($server['extension']) && $server['extension'] === true && extension_loaded('memcached')) {
                $info += Helpers::getExtIniInfo('memcached');
            }

            return ['array' => Helpers::convertTypesToString($info)];
        } catch (MemcachedException $e) {
            return ['tab_error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function keysTab(): array {
        if (isset($_POST['submit_import_key'])) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::import(
                    fn (string $key): bool => $this->memcached->exists($key),
                    fn (string $key, string $value, int $ttl): bool => $this->memcached->set(urldecode($key), base64_decode($value), $ttl)
                );
            } else {
                echo Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        $raw_key_lines = $this->getAllKeys();

        if (isset($_GET['export_btn'])) {
            $keys_to_export = [];
            foreach ($raw_key_lines as $line) {
                $key_data = $this->memcached->parseLine($line);
                if (isset($key_data['key'])) {
                    $keys_to_export[] = [
                        'key' => $key_data['key'],
                        'ttl' => ($key_data['exp'] ?? -1) === -1 ? -1 : ($key_data['exp'] - time()),
                    ];
                }
            }

            Helpers::export($keys_to_export, 'memcached_backup', function (string $key): ?string {
                $value = $this->memcached->get(urldecode($key));

                return $value !== false ? base64_encode($value) : null;
            });
        }

        $paginator = new Paginator($raw_key_lines);
        $paginated_raw_lines = $paginator->getPaginated();

        if (Http::get('view', Config::get('listview', 'table')) === 'tree') {
            $keys_to_display = $this->keysTreeView($paginated_raw_lines);
        } else {
            $keys_to_display = $this->keysTableView($paginated_raw_lines);
        }

        return [
            'keys'      => $keys_to_display,
            'all_keys'  => $this->memcached->getServerStats()['curr_items'],
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString([], ['view' => 'key', 'key' => '__key__']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commandsStatsTab(): array {
        try {
            $info = $this->memcached->getServerStats();
            $commands = $this->commandsStatsData($info);
        } catch (MemcachedException $e) {
            $commands = ['error' => $e->getMessage()];
        }

        return ['commands' => $commands];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function itemsTab(): array {
        $stats = $this->memcached->getItemsStats();

        $items = array_map(static function (array $item): array {
            $fields = [
                'number'                => ['Items', 'number'],
                'number_hot'            => ['HOT LRU', 'number'],
                'number_warm'           => ['WARM LRU', 'number'],
                'number_cold'           => ['COLD LRU', 'number'],
                'number_temp'           => ['TEMP LRU', 'number'],
                'age_hot'               => ['Age (HOT)', 'seconds'],
                'age_warm'              => ['Age (WARM)', 'seconds'],
                'age'                   => ['Age (LRU)', 'seconds'],
                'mem_requested'         => ['Memory Requested', 'bytes'],
                'evicted'               => ['Evicted', 'number'],
                'evicted_nonzero'       => ['Evicted Non-Zero', 'number'],
                'evicted_time'          => ['Evicted Time', 'number'],
                'outofmemory'           => ['Out of Memory', 'number'],
                'tailrepairs'           => ['Tail Repairs', 'number'],
                'reclaimed'             => ['Reclaimed', 'number'],
                'expired_unfetched'     => ['Expired Unfetched', 'number'],
                'evicted_unfetched'     => ['Evicted Unfetched', 'number'],
                'evicted_active'        => ['Evicted Active', 'number'],
                'crawler_reclaimed'     => ['Crawler Reclaimed', 'number'],
                'crawler_items_checked' => ['Crawler Items Checked', 'number'],
                'lrutail_reflocked'     => ['LRU Tail Reflocked', 'number'],
                'moves_to_cold'         => ['Moves to COLD', 'number'],
                'moves_to_warm'         => ['Moves to WARM', 'number'],
                'moves_within_lru'      => ['Moves within LRU', 'number'],
                'direct_reclaims'       => ['Direct Reclaims', 'number'],
                'hits_to_hot'           => ['Hits to HOT', 'number'],
                'hits_to_warm'          => ['Hits to WARM', 'number'],
                'hits_to_cold'          => ['Hits to COLD', 'number'],
                'hits_to_temp'          => ['Hits to TEMP', 'number'],
            ];

            return Helpers::formatFields($fields, $item);
        }, $stats);

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function metricsTab(): array {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return ['tab_error' => 'Metrics are disabled because the PDO SQLite driver is not available. Install the sqlite3 extension for PHP.'];
        }

        try {
            $health = $this->getHealthChecks($this->memcached->getServerStats());
        } catch (MemcachedException) {
            $health = [];
        }

        return ['health' => $health];
    }

    /**
     * @throws MemcachedException
     */
    private function mainDashboard(): string {
        $tab = Http::get('tab', '');
        $tab = array_key_exists($tab, $this->tabs) ? $tab : array_key_first($this->tabs);

        $tab_data = match ($tab) {
            'keys' => $this->keysTab(),
            'analysis' => $this->analysisTab(),
            'commands_stats' => $this->commandsStatsTab(),
            'slabs' => $this->slabsTab(),
            'items' => $this->itemsTab(),
            'metrics' => $this->metricsTab(),
            'moreinfo' => ['data' => $this->moreinfoTab(), 'tpl' => 'partials/info_table'],
            default => [],
        };

        $tpl = $tab_data['tpl'] ?? 'dashboards/memcached/'.$tab;
        $data = $tab_data['data'] ?? $tab_data;

        return $data['tab_error'] ?? $this->template->render($tpl, $data);
    }
}
