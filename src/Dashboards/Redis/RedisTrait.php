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
        if (extension_loaded('redis')) {
            $title = 'PHP Redis extension <span>v'.phpversion('redis').'</span>';
        } elseif (class_exists(Predis::class)) {
            $title = 'Predis <span>v'.Predis::VERSION.'</span>';
        }

        try {
            $server_info = $this->redis->getInfo();

            $count_of_all_keys = 0;

            foreach ($server_info['keyspace'] as $value) {
                $db = explode(',', $value);
                $keys = explode('=', $db[0]);
                $count_of_all_keys += (int) $keys[1];
            }

            $panels = [
                [
                    'title'     => $title ?? null,
                    'moreinfo'  => true,
                    'server_id' => $this->current_server,
                    'data'      => [
                        'Version' => $server_info['server']['redis_version'],
                        'Uptime'  => Format::seconds((int) $server_info['server']['uptime_in_seconds']),
                    ],
                ],
                [
                    'title' => 'Stats',
                    'data'  => [
                        'Connected clients' => $server_info['clients']['connected_clients'],
                        'Memory used'       => Format::bytes((int) $server_info['memory']['used_memory']),
                        'Keys'              => Format::number($count_of_all_keys).' (all databases)',
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
            Http::redirect(['db']);
        }

        try {
            $type = $this->redis->getType($key);
        } catch (DashboardException $e) {
            return $e->getMessage();
        }

        if (isset($_GET['deletesub'])) {
            switch ($type) {
                case 'set':
                    $subkey = Http::get('member', 0);
                    break;
                case 'list':
                    $subkey = Http::get('index', 0);
                    break;
                case 'zset':
                    $subkey = Http::get('range', 0);
                    break;
                case 'hash':
                    $subkey = Http::get('hash_key', '');
                    break;
                case 'stream':
                    $subkey = Http::get('stream_id', '');
                    break;
                default:
                    $subkey = null;
            }

            $this->deleteSubKey($type, $key, $subkey);

            Http::redirect(['db', 'key', 'view', 'p']);
        }

        if (isset($_GET['delete'])) {
            $this->redis->del($key);
            Http::redirect(['db']);
        }

        if (isset($_GET['export']) && $dump = $this->redis->dump($key)) {
            Helpers::export($key, $dump, 'bin', 'application/octet-stream');
        }

        $value = $this->getAllKeyValues($type, $key);

        $paginator = '';
        $encode_fn = null;
        $is_formatted = null;

        if (is_array($value)) {
            $items = $this->formatViewItems($key, $value, $type);

            $paginator = new Paginator($this->template, $items, [['db', 'view', 'key', 'pp'], ['p' => '']]);
            $value = $paginator->getPaginated();
            $paginator = $paginator->render();
        } else {
            [$value, $encode_fn, $is_formatted] = Value::format($value);
        }

        $ttl = $this->redis->ttl($key);

        $size = $this->redis->rawCommand('MEMORY', 'usage', $key); // requires Redis >= 4.0.0

        return $this->template->render('partials/view_key', [
            'key'            => $key,
            'value'          => $value,
            'type'           => $type,
            'ttl'            => Format::seconds($ttl),
            'size'           => is_int($size) ? Format::bytes($size) : null,
            'encode_fn'      => $encode_fn,
            'formatted'      => $is_formatted,
            'add_subkey_url' => Http::queryString(['db'], ['form' => 'new', 'key' => $key]),
            'deletesub_url'  => Http::queryString(['db', 'view', 'p'], ['deletesub' => 'key', 'key' => $key]),
            'edit_url'       => Http::queryString(['db'], ['form' => 'edit', 'key' => $key]),
            'export_url'     => Http::queryString(['db', 'view', 'p', 'key'], ['export' => 'key']),
            'delete_url'     => Http::queryString(['db', 'view'], ['delete' => 'key', 'key' => $key]),
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

        if ($old_key !== '' && $old_key !== $key) {
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

        Http::redirect(['db'], ['view' => 'key', 'key' => $key]);
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
                $type = $this->redis->getType($key);
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

        foreach ($keys_array as $key) {
            try {
                $type = $this->redis->getType($key);
            } catch (DashboardException $e) {
                $type = 'unknown';
            }

            $ttl = $this->redis->ttl($key);
            $total = $this->getCountOfItemsInKey($type, $key);

            $keys[] = [
                'key'    => $key,
                'base64' => true,
                'items'  => [
                    'link_title' => ($total !== null ? '('.$total.' items) ' : '').$key,
                    'type'       => $type,
                    'ttl'        => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
                ],
            ];
        }

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
            $dbs = (array) $this->redis->config('GET', 'databases');
            $db_count = $dbs['databases'] ?? 1;
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

    private function select(): string {
        $servers = Helpers::serverSelector($this->template, $this->servers, $this->current_server);

        try {
            $databases = $this->template->render('components/select', [
                'id'       => 'db_select',
                'options'  => $this->getDatabases(),
                'selected' => Http::get('db', $this->servers[$this->current_server]['database'] ?? 0),
                'class'    => 'mb-3',
            ]);
        } catch (DashboardException|Exception $e) {
            $databases = '';
        }

        return $servers.$databases;
    }

    /**
     * @throws Exception
     */
    private function mainDashboard(): string {
        $keys = $this->getAllKeys();

        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                function (string $key): bool {
                    $exists = $this->redis->exists($key);

                    return is_int($exists) && $exists > 0;
                },
                fn (string $key, string $value, int $expire): bool => $this->redis->restore($key, ($expire === -1 ? 0 : $expire), $value),
                'application/octet-stream'
            );
        }

        $paginator = new Paginator($this->template, $keys, [['db', 's', 'pp'], ['p' => '']]);

        return $this->template->render('dashboards/redis/redis', [
            'select'      => $this->select(),
            'panels'      => $this->panels(),
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => $this->redis->dbSize(),
            'new_key_url' => Http::queryString(['db'], ['form' => 'new']),
            'paginator'   => $paginator->render(),
            'view_key'    => Http::queryString(['db', 's'], ['view' => 'key', 'key' => '__key__']),
        ]);
    }
}
