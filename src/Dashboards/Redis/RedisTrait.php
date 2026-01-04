<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use JsonException;
use PDO;
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

    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(): array {
        if ($this->client === 'redis') {
            $title = 'Redis extension v'.phpversion('redis');
        } elseif ($this->client === 'predis') {
            $title = 'Predis v'.Predis::VERSION;
        }

        try {
            $info = $this->redis->getInfo(null, [
                'redis_version',
                'used_memory',
                'maxmemory',
                'keyspace_hits',
                'keyspace_misses',
                'total_connections_received',
                'total_commands_processed',
            ]);

            $panels = [
                $this->mainPanel($info, $title ?? null),
                $this->memoryPanel($info),
                $this->statsPanel($info),
            ];

            $panels = array_filter($panels);

            return array_values($panels);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @return array{title: ?string, moreinfo: bool, data: array<int|string, mixed>}
     *
     * @throws Exception
     */
    private function mainPanel(array $info, ?string $title): array {
        $server_info = $info['server'] ?? [];
        $cluster_info = $info['cluster'] ?? [];
        $replication_info = $info['replication'] ?? [];
        $stats_info = $info['stats'] ?? [];

        $hits = (int) ($stats_info['keyspace_hits'] ?? 0);
        $misses = (int) ($stats_info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        $hit_rate = $total > 0 ? round(($hits / $total) * 100, 2) : 0;

        $role = null;

        if (!$this->is_cluster && isset($replication_info['role'])) {
            $slaves = $replication_info['connected_slaves'] ?? 0;
            $role = ['Role', $replication_info['role'].', connected slaves '.$slaves];
        }

        $data = [
            'Version' => ($server_info['redis_version'] ?? 'N/A').(isset($server_info['redis_mode']) ? ', '.$server_info['redis_mode'].' mode' : ''),
            'Cluster' => ($cluster_info['cluster_enabled'] ?? 0) ? 'Enabled' : 'Disabled',
            'Uptime'  => Format::seconds((int) ($server_info['uptime_in_seconds'] ?? 0)),
            $role,
            'Keys'    => Format::number($this->getKeysCountFromInfo($info)).' (all databases)',
            ['Hits / Misses', Format::number($hits).' / '.Format::number($misses).' ('.$hit_rate.'%)', $hit_rate, 'higher'],
        ];

        return [
            'title'    => $title,
            'moreinfo' => true,
            'data'     => array_filter($data),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @throws Exception
     */
    private function getKeysCountFromInfo(array $info): int {
        $count_of_all_keys = 0;

        if (!$this->is_cluster) {
            $keyspace_info = $info['keyspace'] ?? [];

            foreach ($keyspace_info as $entry) {
                if (is_string($entry) && str_contains($entry, 'keys=')) {
                    parse_str(str_replace(',', '&', $entry), $parsed);
                    $count_of_all_keys += (int) ($parsed['keys'] ?? 0);
                }
            }
        } else {
            $count_of_all_keys = $this->redis->databaseSize();
        }

        return $count_of_all_keys;
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @return array{title: string, data: array<int|string, mixed>}|null
     */
    private function memoryPanel(array $info): ?array {
        if (!isset($info['memory'])) {
            return null;
        }

        $memory_info = $info['memory'];
        $used_memory = (int) ($memory_info['used_memory']);
        $max_memory = (int) ($memory_info['maxmemory']);
        $used_memory_formatted = ['Used', Format::bytes($used_memory)];

        if ($max_memory > 0) {
            $memory_usage = round(($used_memory / $max_memory) * 100, 2);
            $used_memory_formatted = ['Used', Format::bytes($used_memory).' ('.$memory_usage.'%)', $memory_usage];
        }

        return [
            'title' => 'Memory',
            'data'  => [
                'Total'               => $max_memory > 0 ? Format::bytes($max_memory, 0) : '∞',
                $used_memory_formatted,
                'Free'                => $max_memory > 0 ? Format::bytes($max_memory - $used_memory) : '∞',
                'Peak memory usage'   => Format::bytes((int) ($memory_info['used_memory_peak'] ?? 0)),
                'Fragmentation ratio' => $memory_info['mem_fragmentation_ratio'] ?? 'N/A',
                'Lua memory usage'    => Format::bytes((int) ($memory_info['used_memory_lua'] ?? 0)),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @return array{title: string, data: array<int|string, mixed>}|null
     */
    private function statsPanel(array $info): ?array {
        if (!isset($info['stats'], $info['clients'])) {
            return null;
        }

        $stats_info = $info['stats'];
        $clients_info = $info['clients'];
        $maxclients = isset($clients_info['maxclients']) ? ' / '.Format::number((int) $clients_info['maxclients']) : '';

        return [
            'title' => 'Stats',
            'data'  => [
                'Connected clients'            => Format::number((int) ($clients_info['connected_clients'] ?? 0)).$maxclients,
                'Blocked clients'              => Format::number((int) ($clients_info['blocked_clients'] ?? 0)),
                'Total connections received'   => Format::number((int) ($stats_info['total_connections_received'] ?? 0)),
                'Total commands processed'     => Format::number((int) ($stats_info['total_commands_processed'] ?? 0)),
                'Instantaneous ops per second' => Format::number((int) ($stats_info['instantaneous_ops_per_sec'] ?? 0)),
            ],
        ];
    }

    /**
     * @throws Exception
     */
    private function deleteAllKeys(): string {
        if ($this->redis->flushDatabase()) {
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
        $type = Http::post('rtype', '');
        $old_key = Http::post('old_key', '');

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
            $this->redis->rename($old_key, $key);
        }

        $this->store($type, $key, $value, $old_value, [
            'list_index' => $_POST['index'] ?? '',
            'zset_score' => Http::post('score', 0),
            'hash_key'   => Http::post('hash_key', ''),
            'stream_id'  => Http::post('stream_id', '*'),
            'ttl'        => Http::post('expire', 0),
        ]);

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Add/edit a form.
     *
     * @throws Exception
     */
    private function form(): string {
        $key = (string) Http::get('key', Http::post('key', ''));
        $type = Http::post('rtype', 'string');
        $index = $_POST['index'] ?? '';
        $score = Http::post('score', 0);
        $hash_key = Http::post('hash_key', '');
        $expire = Http::post('expire', -1);
        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');
        $stream_id = Http::post('stream_id', '*');

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
            [$value, $index, $score, $hash_key, $stream_id] = $this->getKeyValue($type, $key);
        }

        $value = Value::converter($value, $encoder, 'view');

        return $this->template->render('dashboards/redis/form', [
            'key'       => $key,
            'value'     => $value,
            'types'     => $this->getAllTypes(),
            'type'      => $type,
            'index'     => $index,
            'score'     => $score,
            'hash_key'  => $hash_key,
            'expire'    => $expire,
            'encoders'  => Config::getEncoders(),
            'encoder'   => $encoder,
            'stream_id' => $stream_id,
        ]);
    }

    /**
     * @return array<int, array<string, string|int>>
     *
     * @throws Exception
     */
    public function getAllKeys(): array {
        $filter = Http::get('s', '*');
        $this->template->addGlobal('search_value', $filter);

        if (isset($this->servers[$this->current_server]['scansize']) || !$this->isCommandSupported('KEYS')) {
            $scansize = (int) ($this->servers[$this->current_server]['scansize'] ?? 1000);
            $keys_array = $this->redis->scanKeys($filter, $scansize);
        } else {
            $keys_array = $this->redis->keys($filter);
        }

        return $keys_array;
    }

    public function isCommandSupported(string $command): bool {
        try {
            $commands = $this->redis->getCommands();
            $is_supported = in_array(strtolower($command), $commands, true);
        } catch (Exception) {
            $is_supported = false;
        }

        return $is_supported;
    }

    /**
     * @param array<int|string, mixed> $keys
     *
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    private function pipeline(array $keys): array {
        $keys = array_map(static fn ($key): array => ['key' => $key], $keys);
        $keys_array = array_column($keys, 'key');

        return $this->redis->pipelineKeys($keys_array);
    }

    /**
     * @param array<int|string, mixed> $keys_array
     *
     * @return array<int, array<string, string|int>>
     *
     * @throws Exception
     */
    public function keysTableView(array $keys_array): array {
        $pipeline = $this->pipeline($keys_array);
        $formatted_keys = [];

        foreach ($keys_array as $key) {
            $formatted_keys[] = [
                'key'    => $key,
                'items'  => $pipeline[$key]['count'] ?? null,
                'base64' => true,
                'info'   => [
                    'link_title' => $key,
                    'bytes_size' => $pipeline[$key]['size'],
                    'type'       => $pipeline[$key]['type'],
                    'ttl'        => $pipeline[$key]['ttl'] === -1 ? 'Doesn\'t expire' : $pipeline[$key]['ttl'],
                ],
            ];
        }

        return Helpers::sortKeys($this->template, $formatted_keys);
    }

    /**
     * @param array<int|string, mixed> $keys_array
     *
     * @return array<int, array<string, string|int>>
     *
     * @throws Exception
     */
    public function keysTreeView(array $keys_array): array {
        $pipeline = $this->pipeline($keys_array);
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        $this->template->addGlobal('separator', $separator);

        $tree = [];

        foreach ($keys_array as $key) {
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
                        'items'  => $pipeline[$key]['count'] ?? null,
                        'base64' => true,
                        'info'   => [
                            'bytes_size' => $pipeline[$key]['size'],
                            'type'       => $pipeline[$key]['type'],
                            'ttl'        => $pipeline[$key]['ttl'] === -1 ? 'Doesn\'t expire' : $pipeline[$key]['ttl'],
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
     * @return array<int, string>
     *
     * @throws Exception
     */
    private function getDatabases(): array {
        $databases = [];

        if (isset($this->servers[$this->current_server]['databases'])) {
            $db_count = (int) $this->servers[$this->current_server]['databases'];
        } else {
            $config = $this->redis->config('GET', 'databases');
            $db_count = (int) ($config['databases'] ?? 16);
        }

        $keyspace = $this->redis->parseSectionData('keyspace');

        for ($d = 0; $d < $db_count; $d++) {
            $label = 'Database '.$d;

            if (isset($keyspace['db'.$d]['keys'])) {
                $count = (int) $keyspace['db'.$d]['keys'];
                $label .= ' ('.Format::number($count).' keys)';
            }

            $databases[$d] = $label;
        }

        return $databases;
    }

    private function dbSelect(): string {
        if ($this->is_cluster) {
            return '';
        }

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

    /**
     * @throws Exception
     */
    private function slowlog(): string {
        if (!$this->isCommandSupported('SLOWLOG')) {
            return $this->template->render('components/tabs', ['links' => ['keys' => 'Keys', 'slowlog' => 'Slow Log',]]).
                'Slowlog is disabled on your server.';
        }

        if (isset($_GET['resetlog'])) {
            $this->redis->resetSlowlog();
            Http::redirect(['tab']);
        }

        if (isset($_POST['save'])) {
            $this->redis->execConfig('SET', 'slowlog-max-len', Http::post('slowlog_max_items', '50'));
            $this->redis->execConfig('SET', 'slowlog-log-slower-than', Http::post('slowlog_slower_than', '1000'));
            Http::redirect(['tab']);
        }

        $slowlog_max_items = (int) $this->redis->execConfig('GET', 'slowlog-max-len')['slowlog-max-len'];
        $slowlog_items = $this->redis->getSlowlog($slowlog_max_items);
        $slowlog_slower_than = $this->redis->execConfig('GET', 'slowlog-log-slower-than')['slowlog-log-slower-than'];

        return $this->template->render('dashboards/redis/redis', [
            'slowlog' => [
                'items'       => $slowlog_items ?? [],
                'max_items'   => $slowlog_max_items,
                'slower_than' => $slowlog_slower_than ?? 1000,
            ],
        ]);
    }

    private function metrics(): string {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return $this->template->render('components/tabs', ['links' => ['keys' => 'Keys', 'slowlog' => 'Slow Log',]]).
                'Metrics are disabled because the PDO SQLite driver is not available. Install the sqlite3 extension for PHP.';
        }

        return $this->template->render('dashboards/redis/redis');
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
                    return $this->redis->restoreKeys($key, ($ttl === -1 ? 0 : $ttl), hex2bin($value));
                }
            );
        }

        if (Http::get('tab') === 'slowlog') {
            return $this->slowlog();
        }

        if (Http::get('tab') === 'metrics') {
            return $this->metrics();
        }

        $keys = $this->getAllKeys();

        if (isset($_GET['export_btn'])) {
            Helpers::export($this->keysTableView($keys), 'redis_backup', fn (string $key): string => bin2hex($this->redis->dump($key)));
        }

        $paginator = new Paginator($this->template, $keys);
        $paginated_keys = $paginator->getPaginated();

        if (Http::get('view', Config::get('listview', 'table')) === 'tree') {
            $keys_to_display = $this->keysTreeView($paginated_keys);
        } else {
            $keys_to_display = $this->keysTableView($paginated_keys);
        }

        return $this->template->render('dashboards/redis/redis', [
            'keys'      => $keys_to_display,
            'all_keys'  => $this->redis->databaseSize(),
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString(['s'], ['view' => 'key', 'key' => '__key__']),
        ]);
    }
}
