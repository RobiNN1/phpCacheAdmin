<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use APCUIterator;
use RobiNN\Pca\Config;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait APCuTrait {
    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(): array {
        $info = apcu_cache_info(true);
        $memory_info = apcu_sma_info(true);

        $total_memory = $memory_info['num_seg'] * $memory_info['seg_size'];
        $memory_used = $total_memory - $memory_info['avail_mem'];
        $memory_usage = round(($memory_used / $total_memory) * 100, 2);

        $num_hits = (int) $info['num_hits'];
        $num_misses = (int) $info['num_misses'];
        $hit_rate = $num_hits !== 0 ? round(($num_hits / ($num_hits + $num_misses)) * 100, 2) : 0;

        return [
            [
                'title'    => 'APCu extension v'.phpversion('apcu'),
                'moreinfo' => true,
                'data'     => [
                    'Start time'       => Format::time($info['start_time']),
                    'Uptime'           => Format::seconds(time() - $info['start_time']),
                    'Cache full count' => $info['expunges'],
                ],
            ],
            [
                'title' => 'Memory',
                'data'  => [
                    'Type'  => $info['memory_type'].' - '.$memory_info['num_seg'].' segment(s)',
                    'Total' => Format::bytes((int) $total_memory, 0),
                    ['Used', Format::bytes((int) $memory_used).' ('.$memory_usage.'%)', $memory_usage],
                    'Free'  => Format::bytes((int) $memory_info['avail_mem']),
                ],
            ],
            [
                'title' => 'Stats',
                'data'  => [
                    'Slots'    => $info['num_slots'],
                    'Keys'     => Format::number((int) $info['num_entries']),
                    ['Hits / Misses', Format::number($num_hits).' / '.Format::number($num_misses).' (Rate '.$hit_rate.'%)', $hit_rate, 'higher'],
                    'Expunges' => Format::number((int) $info['expunges']),
                ],
            ],
        ];
    }

    private function moreInfo(): string {
        $info = (array) apcu_cache_info(true);

        foreach (apcu_sma_info(true) as $mem_name => $mem_value) {
            if (!is_array($mem_value)) {
                $info['memory'][$mem_name] = $mem_value;
            }
        }

        $info += Helpers::getExtIniInfo('apcu');

        return $this->template->render('partials/info_table', [
            'panel_title' => 'APCu Info',
            'array'       => Helpers::convertTypesToString($info),
        ]);
    }

    private function getKeySize(string $key): int {
        $cache_info = apcu_cache_info();

        // For some reason apcu_key_info() does not contain the key size
        foreach ($cache_info['cache_list'] as $entry) {
            if ($entry['info'] === $key) {
                return $entry['mem_size'];
            }
        }

        return 0;
    }

    private function viewKey(): string {
        $key = Http::get('key', '');

        if (apcu_exists($key) === false) {
            Http::redirect();
        }

        $value = Helpers::mixedToString(apcu_fetch($key));
        $key_data = apcu_key_info($key);
        $ttl = $key_data['ttl'] === 0 ? -1 : $key_data['creation_time'] + $key_data['ttl'] - time();

        if (isset($_GET['export'])) {
            Helpers::export(
                [['key' => $key, 'ttl' => $ttl]],
                $key,
                static fn (string $key): string => base64_encode(serialize(apcu_fetch($key)))
            );
        }

        if (isset($_GET['delete'])) {
            apcu_delete($key);
            Http::redirect();
        }

        [$formatted_value, $encode_fn, $is_formatted] = Value::format($value);

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $formatted_value,
            'ttl'        => Format::seconds($ttl),
            'size'       => Format::bytes($this->getKeySize($key)),
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
            'delete_url' => Http::queryString(['view'], ['delete' => 'key', 'key' => $key]),
        ]);
    }

    public function saveKey(): void {
        $key = Http::post('key', '');
        $expire = Http::post('expire', 0);
        $old_key = Http::post('old_key', '');
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
            apcu_delete($old_key);
        }

        apcu_store($key, $value, $expire);

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Add/edit form.
     */
    private function form(): string {
        $key = Http::get('key', '');
        $expire = 0;

        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');

        if (isset($_GET['key']) && apcu_exists($key)) {
            $value = Helpers::mixedToString(apcu_fetch($key));
            $info = apcu_key_info($key);
            $expire = $info['ttl'];
        }

        if (isset($_POST['submit'])) {
            $this->saveKey();
        }

        $value = Value::converter($value, $encoder, 'view');

        return $this->template->render('partials/form', [
            'exp_attr' => 'min="0"',
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    public function getAllKeys(): array {
        $search = Http::get('s', '');
        $this->template->addGlobal('search_value', $search);

        $keys = [];
        $time = time();

        $iterator = new APCUIterator('/.*/', APC_ITER_ALL, 0, APC_LIST_ACTIVE);

        foreach ($iterator as $item) {
            $key = $item['key'];

            if ($search !== '' && stripos($key, $search) === false) {
                continue;
            }

            $ttl = $item['ttl'];
            $keys[] = [
                'key'           => $key,
                'mem_size'      => $item['mem_size'] ?? 0,
                'num_hits'      => $item['num_hits'] ?? 0,
                'access_time'   => $item['access_time'] ?? 0,
                'creation_time' => $item['creation_time'] ?? 0,
                'ttl'           => $ttl === 0 ? 'Doesn\'t expire' : ($item['creation_time'] + $ttl - $time),
            ];
        }

        return $keys;
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int, array<string, string|int>>
     */
    public function keysTableView(array $keys): array {
        $formatted_keys = [];

        foreach ($keys as $key_data) {
            $formatted_keys[] = [
                'key'    => $key_data['key'],
                'base64' => true,
                'info'   => [
                    'link_title'         => $key_data['key'],
                    'bytes_size'         => $key_data['mem_size'],
                    'number_hits'        => $key_data['num_hits'],
                    'timediff_last_used' => $key_data['access_time'],
                    'time_created'       => $key_data['creation_time'],
                    'ttl'                => $key_data['ttl'],
                ],
            ];
        }

        return Helpers::sortKeys($this->template, $formatted_keys);
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int, array<string, string|int>>
     */
    public function keysTreeView(array $keys): array {
        $separator = Config::get('apcuseparator', ':');
        $this->template->addGlobal('separator', $separator);

        $tree = [];

        foreach ($keys as $key_data) {
            $key = $key_data['key'];
            $parts = explode($separator, $key);

            /** @var array<int|string, mixed> $current */
            $current = &$tree;
            $path = '';

            foreach ($parts as $i => $part) {
                $path = $path !== '' && $path !== '0' ? $path.$separator.$part : $part;

                if ($i === count($parts) - 1) { // check last part
                    $current[] = [
                        'type'   => 'key',
                        'name'   => $part,
                        'key'    => $key,
                        'base64' => true,
                        'info'   => [
                            'bytes_size'         => $key_data['mem_size'],
                            'number_hits'        => $key_data['num_hits'],
                            'timediff_last_used' => $key_data['access_time'],
                            'time_created'       => $key_data['creation_time'],
                            'ttl'                => $key_data['ttl'],
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

    private function mainDashboard(): string {
        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                static fn (string $key): bool => apcu_exists($key),
                static function (string $key, string $value, int $ttl): bool {
                    return apcu_store($key, unserialize(base64_decode($value), ['allowed_classes' => false]), $ttl);
                }
            );
        }

        $keys = $this->getAllKeys();

        if (isset($_GET['export_btn'])) {
            Helpers::export($keys, 'apcu_backup', static fn (string $key): string => base64_encode(serialize(apcu_fetch($key))));
        }

        $paginator = new Paginator($this->template, $keys);
        $paginated_keys = $paginator->getPaginated();

        if (Http::get('view', Config::get('listview', 'table')) === 'tree') {
            $keys_to_display = $this->keysTreeView($paginated_keys);
        } else {
            $keys_to_display = $this->keysTableView($paginated_keys);
        }

        unset($keys, $paginated_keys);

        $info = apcu_cache_info(true);

        return $this->template->render('dashboards/apcu', [
            'keys'      => $keys_to_display,
            'all_keys'  => (int) $info['num_entries'],
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString([], ['view' => 'key', 'key' => '__key__']),
        ]);
    }
}
