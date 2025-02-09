<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use JsonException;
use Predis\Client as Predis;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait RedisTrait {
    use RedisTypes;

    private function panels(): string {
        if ($this->clinet === 'redis') {
            $title = 'PHP Redis extension v'.phpversion('redis');
        } elseif ($this->clinet === 'predis') {
            $title = 'Predis v'.Predis::VERSION;
        }

        try {
            $info = $this->redis->getInfo();

            $count_of_all_keys = 0;

            foreach ($info['keyspace'] as $value) {
                [$keys] = explode(',', $value);
                [, $key_count] = explode('=', $keys);
                $count_of_all_keys += (int) $key_count;
            }

            $used_memory = (int) $info['memory']['used_memory'];
            $max_memory = (int) $info['memory']['maxmemory'];

            if ($max_memory > 0) { // 0 = unlimited
                $memory_usage = round(($used_memory / $max_memory) * 100, 2);
                $used_memory_formatted = ['Used', Format::bytes($used_memory).' ('.$memory_usage.'%)', $memory_usage];
            } else {
                $used_memory_formatted = ['Used', Format::bytes($used_memory)];
            }

            $hits = (int) $info['stats']['keyspace_hits'];
            $misses = (int) $info['stats']['keyspace_misses'];
            $total = $hits + $misses;
            $hit_rate = $total !== 0 ? round(($hits / $total) * 100, 2) : 0;

            $redis_mode = isset($info['server']['redis_mode']) ? ', '.$info['server']['redis_mode'].' mode' : '';

            $panels = [
                [
                    'title'    => $title ?? null,
                    'moreinfo' => true,
                    'data'     => [
                        'Version' => $info['server']['redis_version'].$redis_mode,
                        'Cluster' => $info['cluster']['cluster_enabled'] ? 'Enabled' : 'Disabled',
                        'Uptime'  => Format::seconds((int) $info['server']['uptime_in_seconds']),
                        'Role'    => $info['replication']['role'].', connected slaves '.$info['replication']['connected_slaves'],
                        'Keys'    => Format::number($count_of_all_keys).' (all databases)',
                        ['Hits / Misses', Format::number($hits).' / '.Format::number($misses).' ('.$hit_rate.'%)', $hit_rate, 'higher'],
                    ],
                ],
                [
                    'title' => 'Memory',
                    'data'  => [
                        'Total'               => $max_memory > 0 ? Format::bytes($max_memory, 0) : '&infin;',
                        $used_memory_formatted,
                        'Free'                => $max_memory > 0 ? Format::bytes($max_memory - $used_memory) : '&infin;',
                        'Peak memory usage'   => Format::bytes((int) $info['memory']['used_memory_peak']),
                        'Fragmentation ratio' => $info['memory']['mem_fragmentation_ratio'],
                    ],
                ],
                [
                    'title' => 'Stats',
                    'data'  => [
                        'Connected clients'            => Format::number((int) $info['clients']['connected_clients']).' / '.
                            Format::number((int) $info['clients']['maxclients']),
                        'Blocked clients'              => Format::number((int) $info['clients']['blocked_clients']),
                        'Total connections received'   => Format::number((int) $info['stats']['total_connections_received']),
                        'Total commands processed'     => Format::number((int) $info['stats']['total_commands_processed']),
                        'Instantaneous ops per second' => Format::number((int) $info['stats']['instantaneous_ops_per_sec']),
                    ],
                ],
            ];
        } catch (Exception $e) {
            $panels = ['error' => $e->getMessage()];
        }

        return $this->template->render('partials/info', ['panels' => $panels]);
    }

    /**
     * @throws Exception
     */
    private function deleteAllKeys(): string {
        if ($this->redis->flushDB()) {
            return Helpers::alert($this->template, 'All keys from the current database have been removed.', 'success');
        }

        return Helpers::alert($this->template, 'An error occurred while deleting all keys.', 'error');
    }

    private function moreInfo(): string {
        try {
            $info = $this->redis->getInfo();

            foreach ($this->redis->getModules() as $module) {
                $info['modules'][$module['name']] = $module['ver'];
            }

            if (extension_loaded('redis')) {
                $info += Helpers::getExtIniInfo('redis');
            }

            return $this->template->render('partials/info_table', [
                'panel_title' => Helpers::getServerTitle($this->servers[$this->current_server]),
                'array'       => Helpers::convertTypesToString($info),
            ]);
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Format view array items.
     *
     * @param array<int, mixed> $value_items
     *
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    private function formatViewItems(string $key, array $value_items, string $type): array {
        $items = [];

        foreach ($value_items as $item_key => $item_value) {
            if (is_array($item_value)) {
                try {
                    $item_value = json_encode($item_value, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $item_value = $e->getMessage();
                }
            }

            [$formatted_value, $encode_fn, $is_formatted] = Value::format($item_value);

            $items[] = [
                'key'       => $item_key,
                'value'     => $formatted_value,
                'encode_fn' => $encode_fn,
                'formatted' => $is_formatted,
                'sub_key'   => $type === 'zset' ? (string) $this->redis->zScore($key, $item_value) : $item_key,
            ];
        }

        return $items;
    }

    /**
     * @throws Exception
     */
    private function viewKey(): string {
        $key = Http::get('key', '');

        if (!$this->redis->exists($key)) {
            Http::redirect();
        }

        try {
            $type = $this->redis->getKeyType($key);
        } catch (DashboardException $e) {
            return $e->getMessage();
        }

        if (isset($_GET['deletesub'])) {
            $subkey = match ($type) {
                'set' => Http::get('member', 0),
                'list' => Http::get('index', 0),
                'zset' => Http::get('range', 0),
                'hash' => Http::get('hash_key', ''),
                'stream' => Http::get('stream_id', ''),
                default => null,
            };

            $this->deleteSubKey($type, $key, $subkey);

            Http::redirect(['key', 'view', 'p']);
        }

        if (isset($_GET['delete'])) {
            $this->redis->del($key);
            Http::redirect();
        }

        $ttl = $this->redis->ttl($key);

        if (isset($_GET['export'])) {
            Helpers::export(
                [['key' => $key, 'ttl' => $ttl]],
                $key,
                fn (string $key): string => bin2hex($this->redis->dump($key))
            );
        }

        $value = $this->getAllKeyValues($type, $key);

        $paginator = '';
        $encode_fn = null;
        $is_formatted = null;

        if (is_array($value)) {
            $items = $this->formatViewItems($key, $value, $type);
            $paginator = new Paginator($this->template, $items, [['view', 'key', 'pp'], ['p' => '']]);
            $value = $paginator->getPaginated();
            $paginator = $paginator->render();
        } else {
            [$value, $encode_fn, $is_formatted] = Value::format($value);
        }

        return $this->template->render('partials/view_key', [
            'key'            => $key,
            'value'          => $value,
            'type'           => $type,
            'ttl'            => Format::seconds($ttl),
            'size'           => Format::bytes($this->redis->size($key)),
            'encode_fn'      => $encode_fn,
            'formatted'      => $is_formatted,
            'add_subkey_url' => Http::queryString([], ['form' => 'new', 'key' => $key]),
            'deletesub_url'  => Http::queryString(['view', 'p'], ['deletesub' => 'key', 'key' => $key]),
            'edit_url'       => Http::queryString([], ['form' => 'edit', 'key' => $key]),
            'export_url'     => Http::queryString(['view', 'p', 'key'], ['export' => 'key']),
            'delete_url'     => Http::queryString(['view'], ['delete' => 'key', 'key' => $key]),
            'paginator'      => $paginator,
            'types'          => $this->typesTplOptions(),
        ]);
    }

    /**
     * @throws Exception
     */
    public function saveKey(): void {
        $key = Http::post('key', '');
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');
        $old_value = Http::post('old_value', '');
        $type = Http::post('redis_type', '');
        $old_key = Http::post('old_key', '');

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
            $this->redis->rename($old_key, $key);
        }

        $this->store($type, $key, $value, $old_value, [
            'list_index'   => $_POST['index'] ?? '',
            'zset_score'   => Http::post('score', 0),
            'hash_key'     => Http::post('hash_key', ''),
            'stream_id'    => Http::post('stream_id', '*'),
            'stream_field' => Http::post('field', ''),
        ]);

        $expire = Http::post('expire', 0);

        if ($expire === -1) {
            $this->redis->persist($key);
        } else {
            $this->redis->expire($key, $expire);
        }

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Add/edit a form.
     *
     * @throws Exception
     */
    private function form(): string {
        $key = (string) Http::get('key', Http::post('key', ''));
        $type = Http::post('redis_type', 'string');
        $index = $_POST['index'] ?? '';
        $score = Http::post('score', 0);
        $hash_key = Http::post('hash_key', '');
        $expire = Http::post('expire', -1);
        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');

        if (isset($_POST['submit'])) {
            $this->saveKey();
        }

        // edit|subkeys
        if (isset($_GET['key']) && $this->redis->exists($key)) {
            try {
                $type = $this->redis->getKeyType($key);
            } catch (DashboardException $e) {
                Helpers::alert($this->template, $e->getMessage(), 'error');
                $type = 'unknown';
            }

            $expire = $this->redis->ttl($key);
        }

        if (isset($_GET['key']) && $_GET['form'] === 'edit' && $this->redis->exists($key)) {
            [$value, $index, $score, $hash_key] = $this->getKeyValue($type, $key);
        }

        $value = Value::converter($value, $encoder, 'view');

        return $this->template->render('dashboards/redis/form', [
            'key'      => $key,
            'value'    => $value,
            'types'    => $this->getAllTypes(),
            'type'     => $type,
            'index'    => $index,
            'score'    => $score,
            'hash_key' => $hash_key,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }

    /**
     * @return array<int, array<string, string|int>>
     *
     * @throws Exception
     */
    private function getAllKeys(): array {
        static $keys = [];
        $filter = Http::get('s', '*');

        $this->template->addGlobal('search_value', $filter);

        if (isset($this->servers[$this->current_server]['scansize'])) {
            $keys_array = $this->redis->scanKeys($filter, (int) $this->servers[$this->current_server]['scansize']);
        } else {
            $keys_array = $this->redis->keys($filter);
        }

        $pipeline = $this->redis->pipelineKeys($keys_array);

        foreach ($keys_array as $key) {
            $ttl = $pipeline[$key]['ttl'];
            $total = $pipeline[$key]['count'];

            $keys[] = [
                'key'    => $key,
                'base64' => true,
                'items'  => [
                    'link_title' => ($total !== null ? '('.$total.' items) ' : '').$key,
                    'bytes_size' => $pipeline[$key]['size'],
                    'type'       => $pipeline[$key]['type'],
                    'ttl'        => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
                ],
            ];
        }

        $keys = Helpers::sortKeys($this->template, $keys);

        return $keys;
    }

    /**
     * @return array<int, string>
     *
     * @throws Exception
     */
    private function getDatabases(): array {
        $databases = [];

        if (isset($this->servers[$this->current_server]['databases'])) {
            $db_count = (int) $this->servers[$this->current_server]['databases'];
        } else {
            $db_count = (int) $this->redis->config('GET', 'databases')['databases'];
        }

        for ($d = 0; $d < $db_count; $d++) {
            $keyspace = $this->redis->getInfo('keyspace');
            $keys_in_db = '';

            if (array_key_exists('db'.$d, $keyspace)) {
                $db = explode(',', $keyspace['db'.$d]);
                $keys = explode('=', $db[0]);
                $keys_in_db = ' ('.Format::number((int) $keys[1]).' keys)';
            }

            $databases[$d] = 'Database '.$d.$keys_in_db;
        }

        return $databases;
    }

    private function dbSelect(): string {
        try {
            $databases = $this->template->render('components/select', [
                'id'       => 'db_select',
                'options'  => $this->getDatabases(),
                'selected' => Http::get('db', $this->servers[$this->current_server]['database'] ?? 0),
            ]);
        } catch (DashboardException|Exception) {
            $databases = '';
        }

        return $databases;
    }

    private function slowlog(): string {
        if (isset($_GET['resetlog'])) {
            $this->redis->rawCommand('SLOWLOG', 'RESET');
            Http::redirect(['tab']);
        }

        if (isset($_POST['save'])) {
            $this->redis->config('SET', 'slowlog-max-len', Http::post('slowlog_max_items', '50'));
            $this->redis->config('SET', 'slowlog-log-slower-than', Http::post('slowlog_slower_than', '1000'));
            Http::redirect(['tab']);
        }

        $slowlog_max_items = $this->redis->config('GET', 'slowlog-max-len')['slowlog-max-len'];
        $slowlog_items = $this->redis->rawCommand('SLOWLOG', 'GET', $slowlog_max_items);
        $slowlog_slower_than = $this->redis->config('GET', 'slowlog-log-slower-than')['slowlog-log-slower-than'];

        return $this->template->render('dashboards/redis/redis', [
            'slowlog' => [
                'items'       => $slowlog_items ?? [],
                'max_items'   => $slowlog_max_items ?? '',
                'slower_than' => $slowlog_slower_than ?? '',
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    private function mainDashboard(): string {
        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                function (string $key): bool {
                    $exists = $this->redis->exists($key);

                    return is_int($exists) && $exists > 0;
                },
                function (string $key, string $value, int $ttl): bool {
                    return $this->redis->restore($key, ($ttl === -1 ? 0 : $ttl), hex2bin($value));
                }
            );
        }

        if (Http::get('tab') === 'slowlog') {
            return $this->slowlog();
        }

        $keys = $this->getAllKeys();

        if (isset($_GET['export_btn'])) {
            Helpers::export($keys, 'redis_backup', fn (string $key): string => bin2hex($this->redis->dump($key)));
        }

        $paginator = new Paginator($this->template, $keys, [['s', 'pp'], ['p' => '']]);

        return $this->template->render('dashboards/redis/redis', [
            'keys'      => $paginator->getPaginated(),
            'all_keys'  => $this->redis->dbSize(),
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString(['s'], ['view' => 'key', 'key' => '__key__']),
        ]);
    }
}
