<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use PDO;
use RobiNN\Pca\Config;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait MemcachedTrait {
    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(bool $command_stats = false): array {
        try {
            if (class_exists(PHPMem::class)) {
                $title = 'PHPMem v'.PHPMem::VERSION;
            }

            $info = $this->memcached->getServerStats();

            $memory_usage = ($info['limit_maxbytes'] > 0) ? round(($info['bytes'] / $info['limit_maxbytes']) * 100, 2) : 0;

            $stats = [
                [
                    'title'    => $title ?? null,
                    'moreinfo' => true,
                    'data'     => [
                        'Version' => $info['version'],
                        'Uptime'  => Format::seconds($info['uptime']),
                    ],
                ],
                [
                    'title' => 'Memory',
                    'data'  => [
                        'Total' => Format::bytes($info['limit_maxbytes'], 0),
                        ['Used', Format::bytes($info['bytes']).' ('.$memory_usage.'%)', $memory_usage],
                        'Free'  => Format::bytes($info['limit_maxbytes'] - $info['bytes']),
                    ],
                ],
                [
                    'title' => 'Keys',
                    'data'  => [
                        'Current'             => Format::number($info['curr_items']),
                        'Total (since start)' => Format::number($info['total_items']),
                        'Evictions'           => Format::number($info['evictions']),
                        'Reclaimed'           => Format::number($info['reclaimed']),
                        'Expired Unfetched'   => Format::number($info['expired_unfetched']),
                        'Evicted Unfetched'   => Format::number($info['evicted_unfetched']),
                    ],
                ],
                [
                    'title' => 'Connections',
                    'data'  => [
                        'Current'  => Format::number($info['curr_connections']).' / '.Format::number($info['max_connections']).' max',
                        'Total'    => Format::number($info['total_connections']),
                        'Rejected' => Format::number($info['rejected_connections']),
                    ],
                ],
            ];

            if ($command_stats) {
                return array_merge($stats, $this->commandsStatsData($info));
            }

            return $stats;
        } catch (MemcachedException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @throws MemcachedException
     */
    private function deleteAllKeys(): string {
        if ($this->memcached->flush()) {
            return Helpers::alert($this->template, 'All keys have been removed.', 'success');
        }

        return Helpers::alert($this->template, 'An error occurred while deleting all keys.', 'error');
    }

    private function moreInfo(): string {
        try {
            $info = $this->memcached->getServerStats();
            $info += ['settings' => $this->memcached->getServerStats('settings')];

            if (extension_loaded('memcached') || extension_loaded('memcache')) {
                $memcached = extension_loaded('memcached') ? 'd' : '';
                $info += Helpers::getExtIniInfo('memcache'.$memcached);
            }

            return $this->template->render('partials/info_table', [
                'panel_title' => Helpers::getServerTitle($this->servers[$this->current_server]),
                'array'       => Helpers::convertTypesToString($info),
            ]);
        } catch (MemcachedException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @throws MemcachedException
     */
    private function viewKey(): string {
        $key = Http::get('key', '');

        if (!$this->memcached->exists($key)) {
            Http::redirect();
        }

        $info = $this->memcached->getKeyMeta($key);
        $ttl = $info['exp'] ?? null;
        $ttl = $ttl === 0 ? -1 : $ttl;

        if (isset($_GET['export'])) {
            Helpers::export(
                [['key' => $key, 'ttl' => $ttl]],
                $key,
                fn (string $key): string => base64_encode($this->memcached->getKey($key))
            );
        }

        if (isset($_GET['delete'])) {
            $this->memcached->delete($key);
            Http::redirect();
        }

        $value = $this->memcached->getKey($key);

        [$formatted_value, $encode_fn, $is_formatted] = Value::format($value);

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $formatted_value,
            'ttl'        => $ttl ? Format::seconds($ttl) : null,
            'size'       => isset($info['size']) ? Format::bytes($info['size']) : null,
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
            'delete_url' => Http::queryString(['view'], ['delete' => 'key', 'key' => $key]),
        ]);
    }

    /**
     * @throws MemcachedException
     */
    public function saveKey(): void {
        $key = Http::post('key', '');
        $expire = Http::post('expire', 0);
        $old_key = Http::post('old_key', '');
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
            $this->memcached->delete($old_key);
        }

        $this->memcached->set($key, $value, $expire);

        Http::redirect([], ['view' => 'key', 'ttl' => $expire, 'key' => $key]);
    }

    /**
     * Add/edit form.
     *
     * @throws MemcachedException
     */
    private function form(): string {
        $key = Http::get('key', '');
        $expire = Http::get('ttl', 0);
        $expire = $expire === -1 ? 0 : $expire;

        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');

        if (isset($_GET['key']) && $this->memcached->exists($key)) {
            $value = $this->memcached->getKey($key);
        }

        if (isset($_POST['submit'])) {
            $this->saveKey();
        }

        $value = Value::converter($value, $encoder, 'view');

        return $this->template->render('partials/form', [
            'exp_attr' => 'min="0" max="2592000"',
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }

    /**
     * @return array<int, string>
     *
     * @throws MemcachedException
     */
    public function getAllKeys(): array {
        $search = Http::get('s', '');
        $this->template->addGlobal('search_value', $search);

        $all_key_lines = $this->memcached->getKeys();

        if ($search === '') {
            return $all_key_lines;
        }

        $filtered_lines = [];
        foreach ($all_key_lines as $line) {
            if (preg_match('/key=(\S+)/', $line, $match) && stripos($match[1], $search) !== false) {
                $filtered_lines[] = $line;
            }
        }

        return $filtered_lines;
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<int, array<string, mixed>>
     */
    public function keysTableView(array $raw_lines): array {
        $formatted_keys = [];
        $time = time();

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);
            $ttl = $key_data['exp'] ?? null;
            $ttl_display = $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time;

            $formatted_keys[] = [
                'key'  => $key_data['key'],
                'info' => [
                    'link_title'           => urldecode($key_data['key']),
                    'bytes_size'           => $key_data['size'] ?? 0,
                    'timediff_last_access' => $key_data['la'] ?? 0,
                    'ttl'                  => $ttl_display,
                ],
            ];
        }

        return Helpers::sortKeys($this->template, $formatted_keys);
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<string, mixed>
     * @throws MemcachedException
     */
    public function keysTreeView(array $raw_lines): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        if (version_compare($this->memcached->version(), '1.5.19', '>=')) {
            $separator = urlencode($separator);
        }

        $this->template->addGlobal('separator', urldecode($separator));

        $time = time();

        $tree = [];

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);

            if (!isset($key_data['key'])) {
                continue;
            }

            $ttl = $key_data['exp'] ?? null;
            $ttl_display = $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time;

            $parts = explode($separator, $key_data['key']);

            /** @var array<int|string, mixed> $current */
            $current = &$tree;
            $path = '';

            foreach ($parts as $i => $part) {
                $path = $path !== '' && $path !== '0' ? $path.$separator.$part : $part;

                if ($i === count($parts) - 1) { // check last part
                    $current[] = [
                        'type' => 'key',
                        'name' => urldecode($part),
                        'key'  => $key_data['key'],
                        'info' => [
                            'bytes_size'           => $key_data['size'] ?? 0,
                            'timediff_last_access' => $key_data['la'] ?? 0,
                            'ttl'                  => $ttl_display,
                        ],
                    ];
                } else {
                    if (!isset($current[$part])) {
                        $current[$part] = [
                            'type'     => 'folder',
                            'name'     => $part,
                            'path'     => $path,
                            'children' => [],
                            'expanded' => false,
                        ];
                    }

                    $current = &$current[$part]['children'];
                }
            }
        }

        Helpers::countChildren($tree);

        return $tree;
    }

    /**
     * @param array<int|string, mixed> $info
     *
     * @return array<int|string, mixed>
     */
    private function commandsStatsData(array $info): array {
        $rate = (static fn (int $hits, int $total): float => $hits !== 0 && $total !== 0 ? round(($hits / $total) * 100, 2) : 0);

        $get_hit_rate = $rate($info['get_hits'], $info['cmd_get']);
        $delete_hit_rate = $rate($info['delete_hits'], $info['delete_hits'] + $info['delete_misses']);
        $incr_hit_rate = $rate($info['incr_hits'], $info['incr_hits'] + $info['incr_misses']);
        $decr_hit_rate = $rate($info['decr_hits'], $info['decr_hits'] + $info['decr_misses']);
        $cas_hit_rate = $rate($info['cas_hits'], $info['cas_hits'] + $info['cas_misses']);
        $touch_hit_rate = $rate($info['touch_hits'], $info['cmd_touch']);

        return [
            [
                'title' => 'get',
                'data'  => [
                    'Hits'   => Format::number($info['get_hits']),
                    'Misses' => Format::number($info['get_misses']),
                    ['Hit Rate', $get_hit_rate.'%', $get_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'delete',
                'data'  => [
                    'Hits'   => Format::number($info['delete_hits']),
                    'Misses' => Format::number($info['delete_misses']),
                    ['Hit Rate', $delete_hit_rate.'%', $delete_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'incr',
                'data'  => [
                    'Hits'   => Format::number($info['incr_hits']),
                    'Misses' => Format::number($info['incr_misses']),
                    ['Hit Rate', $incr_hit_rate.'%', $incr_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'decr',
                'data'  => [
                    'Hits'   => Format::number($info['decr_hits']),
                    'Misses' => Format::number($info['decr_misses']),
                    ['Hit Rate', $decr_hit_rate.'%', $decr_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'touch',
                'data'  => [
                    'Hits'   => Format::number($info['touch_hits']),
                    'Misses' => Format::number($info['touch_misses']),
                    ['Hit Rate', $touch_hit_rate.'%', $touch_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'cas',
                'data'  => [
                    'Hits'      => Format::number($info['cas_hits']),
                    'Misses'    => Format::number($info['cas_misses']),
                    ['Hit Rate', $cas_hit_rate.'%', $cas_hit_rate, 'higher'],
                    'Bad Value' => $info['cas_badval'],
                ],
            ],
            [
                'title' => 'set',
                'data'  => [
                    'Total' => Format::number($info['cmd_set']),
                ],
            ],
            [
                'title' => 'flush',
                'data'  => [
                    'Total' => Format::number($info['cmd_flush']),
                ],
            ],
        ];
    }

    private function commandsStats(): string {
        try {
            $info = $this->memcached->getServerStats();
            $commands = $this->commandsStatsData($info);
        } catch (MemcachedException $e) {
            $commands = ['error' => $e->getMessage()];
        }

        return $this->template->render('dashboards/memcached/memcached', ['commands' => $commands]);
    }

    /**
     * @throws MemcachedException
     */
    private function slabs(): string {
        $slabs_stats = $this->memcached->getSlabsStats();

        $slabs = array_map(static function (array $slab): array {
            $fields = [
                'chunk_size'      => ['Chunk Size', 'bytes'],
                'chunks_per_page' => ['Chunks per Page', 'number'],
                'total_pages'     => ['Total Pages', 'number'],
                'total_chunks'    => ['Total Chunks', 'number'],
                'used_chunks'     => ['Used Chunks', 'number'],
                'free_chunks'     => ['Free Chunks', 'number'],
                'free_chunks_end' => ['Free Chunks (End)', 'number'],
                'get_hits'        => ['GET Hits', 'number'],
                'cmd_set'         => ['SET Commands', 'number'],
                'delete_hits'     => ['DELETE Hits', 'number'],
                'incr_hits'       => ['INCREMENT Hits', 'number'],
                'decr_hits'       => ['DECREMENT Hits', 'number'],
                'cas_hits'        => ['CAS Hits', 'number'],
                'cas_badval'      => ['CAS Bad Value', 'number'],
                'touch_hits'      => ['TOUCH Hits', 'number'],
            ];

            return Helpers::formatFields($fields, $slab);
        }, $slabs_stats['slabs']);

        return $this->template->render('dashboards/memcached/memcached', [
            'slabs' => $slabs,
            'meta'  => $slabs_stats['meta'],
        ]);
    }

    /**
     * @throws MemcachedException
     */
    private function items(): string {
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

        return $this->template->render('dashboards/memcached/memcached', ['items' => $items]);
    }

    private function metrics(): string {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return $this->template->render('components/tabs', [
                    'links' => [
                        'keys' => 'Keys', 'commands_stats' => 'Commands Stats', 'slabs' => 'Slabs', 'items' => 'Items', 'metrics' => 'Metrics',
                    ],
                ]).
                'Metrics are disabled because the PDO SQLite driver is not available. Install the sqlite3 extension for PHP.';
        }

        return $this->template->render('dashboards/memcached/memcached');
    }

    /**
     * @throws MemcachedException
     */
    private function mainDashboard(): string {
        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                fn (string $key): bool => $this->memcached->exists($key),
                fn (string $key, string $value, int $ttl): bool => $this->memcached->set(urldecode($key), base64_decode($value), $ttl)
            );
        }

        if (Http::get('tab') === 'commands_stats') {
            return $this->commandsStats();
        }

        if (Http::get('tab') === 'slabs') {
            return $this->slabs();
        }

        if (Http::get('tab') === 'items') {
            return $this->items();
        }

        if (Http::get('tab') === 'metrics') {
            return $this->metrics();
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
                $value = $this->memcached->getKey(urldecode($key));

                return $value !== false ? base64_encode($value) : null;
            });
        }

        $paginator = new Paginator($this->template, $raw_key_lines);
        $paginated_raw_lines = $paginator->getPaginated();

        if (Http::get('view', Config::get('listview', 'table')) === 'tree') {
            $keys_to_display = $this->keysTreeView($paginated_raw_lines);
        } else {
            $keys_to_display = $this->keysTableView($paginated_raw_lines);
        }

        return $this->template->render('dashboards/memcached/memcached', [
            'keys'      => $keys_to_display,
            'all_keys'  => $this->memcached->getServerStats()['curr_items'],
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString([], ['view' => 'key', 'key' => '__key__']),
        ]);
    }
}
