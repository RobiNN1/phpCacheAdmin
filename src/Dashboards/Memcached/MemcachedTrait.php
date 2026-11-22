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
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait MemcachedTrait {
    use MemcachedCommands;
    use MemcachedPanels;
    use MemcachedHealth;
    use MemcachedAnalysis;
    use MemcachedSlabs;
    use MemcachedKeyView;
    use MemcachedKeysList;
    use MemcachedConnections;
    use MemcachedWatcher;
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
        'connections'    => 'Connections',
        'watcher'        => 'Watcher',
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
            $info = $this->readableStats($this->memcached->getServerStats());
            $info += ['settings' => $this->readableStats($this->memcached->getServerStats('settings'))];

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
     * Raw values with a readable form next to them, e.g. "67108864 (64 MB)".
     *
     * @param array<string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    public function readableStats(array $stats): array {
        $bytes = [
            'bytes', 'limit_maxbytes', 'bytes_read', 'bytes_written', 'hash_bytes', 'total_malloced', 'maxbytes', 'item_size_max',
            'slab_chunk_max', 'read_buf_bytes', 'read_buf_bytes_free', 'response_obj_bytes', 'round_robin_bytes', 'slab_page_size',
            'extstore_bytes_used', 'extstore_bytes_written', 'extstore_bytes_read', 'extstore_limit_maxbytes', 'ext_item_size',
        ];

        foreach ($stats as $name => $value) {
            if (!is_int($value) || $value <= 0) {
                continue;
            }

            $readable = match (true) {
                in_array($name, $bytes, true) && $value >= 1024 => Format::bytes($value),
                $name === 'uptime' => Format::seconds($value),
                $name === 'time' => Format::time($value),
                default => null,
            };

            if ($readable !== null) {
                $stats[$name] = $value.' ('.$readable.')';
            }
        }

        return $stats;
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
                    fn (string $key, string $value, int $ttl, array $data = []): bool => $this->memcached->set(
                        urldecode($key),
                        base64_decode($value),
                        $ttl,
                        $this->usesExtension() ? 0 : (int) ($data['flags'] ?? 0)
                    )
                );
            } else {
                echo Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        Helpers::importResultAlert();

        if (isset($_POST['delete_matching'])) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                $this->deleteMatchingKeys();
            } else {
                Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        $raw_key_lines = $this->getAllKeys();

        if ($this->filter_error !== null) {
            Helpers::alert($this->filter_error, 'error');
        }

        if (isset($_GET['export_btn'])) {
            $now = $this->memcached->serverTime();
            $keys_to_export = [];

            foreach ($raw_key_lines as $line) {
                $key_data = $this->memcached->parseLine($line);

                if (isset($key_data['key'])) {
                    $exp = (int) ($key_data['exp'] ?? -1);
                    $keys_to_export[] = ['key' => $key_data['key'], 'ttl' => $exp === -1 ? -1 : max(1, $exp - $now)];
                }
            }

            Helpers::export($keys_to_export, 'memcached_backup', fn (string $key): ?array => $this->exportValue(urldecode($key)));
        }

        $field = static function (string $line, string $name): string {
            return preg_match('/(?:^| )'.$name.'=(\S+)/', $line, $found) === 1 ? $found[1] : '';
        };

        $raw_key_lines = Helpers::sortBeforePaginate($raw_key_lines, [
            'link_title'           => static fn (string $line): string => urldecode($field($line, 'key')),
            'bytes_size'           => static fn (string $line): int => (int) $field($line, 'size'),
            'timediff_last_access' => static fn (string $line): int => (int) $field($line, 'la'),
            'ttl'                  => static fn (string $line): int => (int) $field($line, 'exp'),
        ]);

        $paginator = new Paginator($raw_key_lines, [['pp', 'view', ...$this->keyFilters()->params()], ['p' => '']]);
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
            ] + $this->keyFiltersData(count($raw_key_lines));
    }

    /**
     * The value with its client flags, an import restores both.
     *
     * @return array{value: string, flags?: int}|null
     *
     * @throws MemcachedException
     */
    private function exportValue(string $key): ?array {
        $item = $this->memcached->getItem($key);

        if ($item === null) {
            return null;
        }

        return ['value' => base64_encode($item['value'])] + ($item['flags'] !== 0 && !$this->usesExtension() ? ['flags' => $item['flags']] : []);
    }

    /**
     * With the extension, values are decoded and encoded by it, so the flags are its business.
     */
    private function usesExtension(): bool {
        $server = $this->servers[$this->current_server] ?? [];

        // The same check as in PHPMem, without the extension loaded it falls back to the raw protocol.
        return ($server['extension'] ?? false) && extension_loaded('memcached');
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
            'connections' => $this->connectionsTab(),
            'watcher' => $this->watcherTab(),
            'moreinfo' => ['data' => $this->moreinfoTab(), 'tpl' => 'partials/info_table'],
            default => [],
        };

        $tpl = $tab_data['tpl'] ?? 'dashboards/memcached/'.$tab;
        $data = $tab_data['data'] ?? $tab_data;

        if (isset($data['tab_error'])) {
            return htmlspecialchars((string) $data['tab_error']);
        }

        return $this->template->render($tpl, $data);
    }
}
