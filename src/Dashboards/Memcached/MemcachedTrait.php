<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Config;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait MemcachedTrait {
    private function panels(): string {
        if (class_exists(PHPMem::class)) {
            $title = 'PHPMem v'.PHPMem::VERSION;
        }

        try {
            $info = $this->memcached->getServerStats();

            $memory_usage = round(($info['bytes'] / $info['limit_maxbytes']) * 100, 2);

            $panels = [
                [
                    'title'    => $title ?? null,
                    'moreinfo' => true,
                    'data'     => [
                        'Version'          => $info['version'],
                        'Open connections' => $info['curr_connections'],
                        'Uptime'           => Format::seconds($info['uptime']),
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
        } catch (MemcachedException $e) {
            $panels = ['error' => $e->getMessage()];
        }

        return $this->template->render('partials/info', ['panels' => $panels]);
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
            'exp_attr' => ' min="0" max="2592000"',
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }

    /**
     * @return array<int, array<string, string|int>>
     *
     * @throws MemcachedException
     */
    public function getAllKeys(): array {
        $search = Http::get('s', '');
        $this->template->addGlobal('search_value', $search);

        $all_keys = $this->memcached->getKeys();
        $keys = [];
        $time = time();

        foreach ($all_keys as $key_data) {
            $key_data = $this->memcached->parseLine($key_data);
            $ttl = $key_data['exp'] ?? null;

            if (stripos($key_data['key'], $search) !== false) {
                $keys[] = [
                    'key'  => $key_data['key'],
                    'size' => $key_data['size'],
                    'la'   => $key_data['la'] ?? 0,
                    'ttl'  => $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time,
                ];
            }
        }

        if (Http::get('view', Config::get('list-view', 'table')) === 'tree') {
            return $this->keysTreeView($keys);
        }

        return $this->keysTableView($keys);
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int, array<string, string|int>>
     */
    private function keysTableView(array $keys): array {
        $formatted_keys = [];

        foreach ($keys as $key_data) {
            $formatted_keys[] = [
                'key'  => $key_data['key'],
                'info' => [
                    'link_title'           => urldecode($key_data['key']),
                    'bytes_size'           => $key_data['size'],
                    'timediff_last_access' => $key_data['la'] !== 0 ? $key_data['la'] : null,
                    'ttl'                  => $key_data['ttl'],
                ],
            ];
        }

        return Helpers::sortKeys($this->template, $formatted_keys);
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int, array<string, string|int>>
     *
     * @throws MemcachedException
     */
    private function keysTreeView(array $keys): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        if (version_compare($this->memcached->version(), '1.5.19', '>=')) {
            $separator = urlencode($separator);
        }

        $this->template->addGlobal('separator', urldecode($separator));

        $tree = [];

        foreach ($keys as $key_data) {
            $parts = explode($separator, $key_data['key']);

            /** @var array<int|string, mixed> $current */
            $current = &$tree;
            $path = '';

            foreach ($parts as $i => $part) {
                $path = $path ? $path.$separator.$part : $part;

                if ($i === count($parts) - 1) { // check last part
                    $current[] = [
                        'type' => 'key',
                        'name' => urldecode($part),
                        'key'  => $key_data['key'],
                        'info' => [
                            'bytes_size'           => $key_data['size'],
                            'timediff_last_access' => $key_data['la'] !== 0 ? $key_data['la'] : null,
                            'ttl'                  => $key_data['ttl'],
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

    private function commandsStats(): string {
        try {
            $info = $this->memcached->getServerStats();

            $rate = (static fn (int $hits, int $total): float => $hits !== 0 ? round(($hits / $total) * 100, 2) : 0);

            $get_hit_rate = $rate($info['get_hits'], $info['cmd_get']);
            $delete_hit_rate = $rate($info['delete_hits'], $info['delete_hits'] + $info['delete_misses']);
            $incr_hit_rate = $rate($info['incr_hits'], $info['incr_hits'] + $info['incr_misses']);
            $decr_hit_rate = $rate($info['decr_hits'], $info['decr_hits'] + $info['decr_misses']);
            $cas_hit_rate = $rate($info['cas_hits'], $info['cas_hits'] + $info['cas_misses']);
            $touch_hit_rate = $rate($info['touch_hits'], $info['cmd_touch']);

            $commands = [
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
        } catch (MemcachedException $e) {
            $commands = ['error' => $e->getMessage()];
        }

        return $this->template->render('dashboards/memcached', ['commands' => $commands]);
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

        $keys = $this->getAllKeys();

        if (isset($_GET['export_btn'])) {
            Helpers::export($keys, 'memcached_backup', fn (string $key): string => base64_encode($this->memcached->getKey(urldecode($key))));
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/memcached', [
            'keys'      => $paginator->getPaginated(),
            'all_keys'  => $this->memcached->getServerStats()['curr_items'],
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString([], ['view' => 'key', 'key' => '__key__']),
        ]);
    }
}
