<?php
/**
 * This file is part of phpCacheAdmin.
 *
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use JsonException;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait RedisTrait {
    use TypesTrait;

    /**
     * Get a number of keys form all databases.
     *
     * @param array<string, mixed> $keyspace
     */
    private function getCountOfAllKeys(array $keyspace): int {
        $all_keys = 0;

        foreach ($keyspace as $value) {
            $db = explode(',', $value);
            $keys = explode('=', $db[0]);
            $all_keys += (int) $keys[1];
        }

        return $all_keys;
    }

    /**
     * Get server info for ajax.
     *
     * @return array<string, mixed>
     */
    private function serverInfo(): array {
        try {
            $redis = $this->connect($this->servers[Http::get('panel', 0)]);
            $server_info = $redis->getInfo();

            return [
                'Version'           => $server_info['server']['redis_version'],
                'Connected clients' => $server_info['clients']['connected_clients'],
                'Uptime'            => Format::seconds((int) $server_info['server']['uptime_in_seconds']),
                'Memory used'       => Format::bytes((int) $server_info['memory']['used_memory']),
                'Keys'              => Format::number($this->getCountOfAllKeys($server_info['keyspace'])).' (all databases)',
            ];
        } catch (DashboardException|Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @throws Exception
     */
    private function deleteAllKeys(): string {
        if ($this->redis->flushDB()) {
            $message = 'All keys from the current database have been removed.';
        } else {
            $message = 'An error occurred while deleting all keys.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * @return array<int, mixed>
     */
    private function panels(): array {
        $panels = [];

        foreach ($this->servers as $server) {
            $panels[] = [
                'title'            => $server['name'] ?? $server['host'].':'.$server['port'],
                'server_selection' => true,
                'current_server'   => $this->current_server,
                'moreinfo'         => true,
            ];
        }

        return $panels;
    }

    private function moreInfo(): string {
        try {
            $id = Http::get('moreinfo', 0);
            $server_data = $this->servers[$id];
            $redis = $this->connect($server_data);
            $info = $redis->getInfo();

            if (extension_loaded('redis')) {
                $info += Helpers::getExtIniInfo('redis');
            }

            return $this->template->render('partials/info_table', [
                'panel_title' => $server_data['name'] ?? $server_data['host'].':'.$server_data['port'],
                'array'       => Helpers::convertBoolToString($info),
            ]);
        } catch (DashboardException|Exception $e) {
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

            [$value, $encode_fn, $is_formatted] = Value::format($item_value);

            $items[] = [
                'key'       => $item_key,
                'value'     => $value,
                'encode_fn' => $encode_fn,
                'formatted' => $is_formatted,
                'sub_key'   => $type === 'zset' ? (string) $this->redis->zScore($key, $value) : $item_key,
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
            $this->deleteSubKey($type, $key);

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
            'types'          => $this->getTypesData(),
        ]);
    }

    /**
     * @throws Exception
     */
    private function saveKey(): void {
        $key = Http::post('key', '');
        $value = Value::encode(Http::post('value', ''), Http::post('encoder', ''));
        $old_value = Http::post('old_value', '');
        $type = Http::post('redis_type', '');

        $this->store($type, $key, $value, $old_value);

        $expire = Http::post('expire', 0);

        if ($expire === -1) {
            $this->redis->persist($key);
        } else {
            $this->redis->expire($key, $expire);
        }

        $old_key = Http::post('old_key', '');

        if ($old_key !== '' && $old_key !== $key) {
            $this->redis->rename($old_key, $key);
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
                Helpers::alert($this->template, $e->getMessage());
                $type = 'unknown';
            }
            $expire = $this->redis->ttl($key);
        }

        $is_edit = false;

        if (isset($_GET['key']) && $_GET['form'] === 'edit' && $this->redis->exists($key)) {
            [$value, $index, $score, $hash_key] = $this->getKeyValue($type, $key);
            $is_edit = true;
        }

        $value = Value::decode($value, $encoder);

        return $this->template->render('dashboards/redis/form', [
            'key'      => $key,
            'value'    => $value,
            'types'    => $this->redis->getAllTypes(),
            'type'     => $type,
            'index'    => $index,
            'score'    => $score,
            'hash_key' => $hash_key,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
            'is_edit'  => $is_edit,
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

        foreach ($this->redis->keys($filter) as $key) {
            try {
                $type = $this->redis->getType($key);
            } catch (DashboardException $e) {
                $type = 'unknown';
            }

            $ttl = $this->redis->ttl($key);
            $total = $this->getCountOfItemsInKey($type, $key);

            $keys[] = [
                'key'   => base64_encode($key),
                'items' => [
                    'title' => [
                        'title'      => ($total !== null ? '('.$total.' items) ' : '').$key,
                        'title_attr' => $key,
                        'link'       => Http::queryString(['db', 's'], ['view' => 'key', 'key' => $key]),
                    ],
                    'type'  => $type,
                    'ttl'   => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
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
            $db_count = $dbs['databases'];
        }

        for ($d = 0; $d < $db_count; ++$d) {
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

    /**
     * @throws Exception
     */
    private function mainDashboard(): string {
        $keys = $this->getAllKeys();

        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                fn (string $key): bool => $this->redis->exists($key) > 0,
                function (string $key, string $value, int $expire): bool {
                    return $this->redis->restore($key, ($expire === -1 ? 0 : $expire), $value);
                },
                'application/octet-stream'
            );
        }

        $paginator = new Paginator($this->template, $keys, [['db', 's', 'pp'], ['p' => '']]);

        return $this->template->render('dashboards/redis/redis', [
            'databases'   => $this->getDatabases(),
            'current_db'  => Http::get('db', $this->servers[$this->current_server]['database'] ?? 0),
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => $this->redis->dbSize(),
            'new_key_url' => Http::queryString(['db'], ['form' => 'new']),
            'paginator'   => $paginator->render(),
        ]);
    }
}
