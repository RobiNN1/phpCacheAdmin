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
     * Get server info for ajax.
     * This allows loading data of each server separately.
     *
     * @param array<int, array<string, int|string>> $servers
     *
     * @return array<string, mixed>
     */
    private function serverInfo(array $servers): array {
        try {
            $redis = $this->connect($servers[Http::get('panel', 'int')]);
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
     * Get a number of keys form all databases.
     *
     * @param array<string, mixed> $keyspace
     *
     * @return int
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
     * Delete all keys from the current database.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return string
     * @throws Exception
     */
    private function deleteAllKeys($redis): string {
        if ($redis->flushDB()) {
            $message = 'All keys from the current database have been removed.';
        } else {
            $message = 'An error occurred while deleting all keys.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Delete key or selected keys.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return string
     * @throws Exception
     */
    private function deleteKey($redis): string {
        $keys = explode(',', Http::get('delete'));

        if (count($keys) > 1) {
            foreach ($keys as $key) {
                $redis->del($key);
            }
            $message = 'Keys has been deleted.';
        } elseif ($keys[0] !== '' && $redis->del($keys[0])) {
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } else {
            $message = 'No keys are selected.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Show more info.
     *
     * @param array<int, array<string, int|string>> $servers
     *
     * @return string
     */
    private function moreInfo(array $servers): string {
        try {
            $id = Http::get('moreinfo', 'int');
            $server_data = $servers[$id];
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
     * Get server databases.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return array<int, string>
     * @throws Exception
     */
    private function getDatabases($redis): array {
        $databases = [];
        $servers = Config::get('redis');

        if (isset($servers[$this->current_server]['databases'])) {
            $db_count = (int) $servers[$this->current_server]['databases'];
        } else {
            $dbs = (array) $redis->config('GET', 'databases');
            $db_count = $dbs['databases'];
        }

        for ($d = 0; $d < $db_count; ++$d) {
            $keyspace = $redis->getInfo('keyspace');
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
     * Get all keys with data.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return array<int, array<string, string|int>>
     * @throws Exception
     */
    private function getAllKeys($redis): array {
        static $keys = [];
        $filter = Http::get('s', 'string', '*');

        $this->template->addGlobal('search_value', $filter);

        foreach ($redis->keys($filter) as $key) {
            try {
                $type = $redis->getType($key);
            } catch (DashboardException $e) {
                $type = 'unknown';
            }

            $ttl = $redis->ttl($key);
            $total = $this->getCountOfItemsInKey($redis, $type, $key);

            $keys[] = [
                'key'   => $key,
                'items' => [
                    'title' => ['title' => ($total !== null ? '('.$total.' items) ' : '').$key, 'link' => true, 'full' => $key],
                    'type'  => $type,
                    'ttl'   => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
                ],
            ];
        }

        return $keys;
    }

    /**
     * Main dashboard content.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return string
     * @throws Exception
     */
    private function mainDashboard($redis): string {
        $keys = $this->getAllKeys($redis);

        if (isset($_POST['submit_import_key'])) {
            $this->import($redis);
        }

        $paginator = new Paginator($this->template, $keys, [['db', 's', 'pp'], ['p' => '']]);

        $servers = Config::get('redis');

        return $this->template->render('dashboards/redis/redis', [
            'databases'   => $this->getDatabases($redis),
            'current_db'  => Http::get('db', 'int', $servers[$this->current_server]['database'] ?? 0),
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => $redis->dbSize(),
            'new_key_url' => Http::queryString(['db'], ['form' => 'new']),
            'view_url'    => Http::queryString(['db', 's'], ['view' => 'key', 'key' => '']),
            'paginator'   => $paginator->render(),
        ]);
    }

    /**
     * View key values.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return string
     * @throws Exception
     */
    private function viewKey($redis): string {
        $key = Http::get('key');

        if (!$redis->exists($key)) {
            Http::redirect(['db']);
        }

        try {
            $type = $redis->getType($key);
        } catch (DashboardException $e) {
            return $e->getMessage();
        }

        if (isset($_GET['deletesub'])) {
            $this->deleteSubKey($redis, $type, $key);
        }

        if (isset($_GET['delete'])) {
            $redis->del($key);
            Http::redirect(['db']);
        }

        if (isset($_GET['export'])) {
            header('Content-disposition: attachment; filename='.$key.'.bin');
            header('Content-Type: application/octet-stream');
            echo $redis->dump($key);
            exit;
        }

        $value = $this->getAllKeyValues($redis, $type, $key);

        $paginator = '';
        $encode_fn = null;
        $is_formatted = null;

        if (is_array($value)) {
            $items = $this->formatViewItems($redis, $key, $value, $type);

            $paginator = new Paginator($this->template, $items, [['db', 'view', 'key', 'pp'], ['p' => '']]);
            $value = $paginator->getPaginated();
            $paginator = $paginator->render();
        } else {
            [$value, $encode_fn, $is_formatted] = Value::format($value);
        }

        $ttl = $redis->ttl($key);

        $size = $redis->rawCommand('MEMORY', 'usage', $key); // requires Redis >= 4.0.0

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
     * Format view array items.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     * @param string                                   $key
     * @param array<int, mixed>                        $value_items
     * @param string                                   $type
     *
     * @return array<int, mixed>
     * @throws Exception
     */
    private function formatViewItems($redis, string $key, array $value_items, string $type): array {
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
                'sub_key'   => $type === 'zset' ? (string) $redis->zScore($key, $value) : $item_key,
            ];
        }

        return $items;
    }

    /**
     * Import key.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return void
     * @throws Exception
     */
    private function import($redis): void {
        if ($_FILES['import']['type'] === 'application/octet-stream') {
            $key_name = Http::post('key_name');

            if (!$redis->exists($key_name)) {
                $value = file_get_contents($_FILES['import']['tmp_name']);

                $expire = Http::post('expire', 'int');
                $expire = $expire === -1 ? 0 : $expire;

                $redis->restore($key_name, $expire, $value);

                Http::redirect(['db']);
            }
        }
    }

    /**
     * Add/edit a form.
     *
     * @param Compatibility\Redis|Compatibility\Predis $redis
     *
     * @return string
     * @throws Exception
     */
    private function form($redis): string {
        $key = Http::get('key', 'string', Http::post('key'));
        $type = Http::post('redis_type');
        $index = $_POST['index'] ?? '';
        $score = Http::post('score', 'int');
        $hash_key = Http::post('hash_key');
        $expire = Http::post('expire', 'int', -1);
        $encoder = Http::get('encoder', 'string', 'none');
        $value = Http::post('value');

        if (isset($_POST['submit'])) {
            $this->saveKey($redis);
        }

        // edit|subkeys
        if (isset($_GET['key']) && $redis->exists($key)) {
            try {
                $type = $redis->getType($key);
            } catch (DashboardException $e) {
                Helpers::alert($this->template, $e->getMessage());
                $type = 'unknown';
            }
            $expire = $redis->ttl($key);
        }

        if (isset($_GET['key']) && $_GET['form'] === 'edit' && $redis->exists($key)) {
            [$value, $index, $score, $hash_key] = $this->getKeyValue($redis, $type, $key);
        }

        $value = Value::decode($value, $encoder);

        return $this->template->render('dashboards/redis/form', [
            'key'      => $key,
            'value'    => $value,
            'types'    => $redis->getAllTypes(),
            'type'     => $type,
            'index'    => $index,
            'score'    => $score,
            'hash_key' => $hash_key,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }
}
