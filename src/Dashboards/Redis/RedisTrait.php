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

use Redis;
use RedisException;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait RedisTrait {
    use TypesTrait;

    /**
     * Get server info for ajax.
     * This allows loading data of each server separately.
     *
     * @param array<int, mixed> $servers
     *
     * @return array<string, mixed>
     */
    private function serverInfo(array $servers): array {
        try {
            $redis = $this->connect($servers[Http::get('panel', 'int')]);
            $server_info = $redis->info();

            $all_keys = 0;

            foreach ($server_info as $key => $value) {
                if (Helpers::str_starts_with($key, 'db')) {
                    $db = explode(',', $value);
                    $keys = explode('=', $db[0]);
                    $all_keys += (int) $keys[1];
                }
            }

            $data = [
                'Version'           => $server_info['redis_version'],
                'Connected clients' => $server_info['connected_clients'],
                'Uptime'            => Helpers::formatSeconds($server_info['uptime_in_seconds']),
                'Memory used'       => Helpers::formatBytes($server_info['used_memory']),
                'Keys'              => Helpers::formatNumber($all_keys).' (all databases)',
            ];
        } catch (DashboardException|RedisException $e) {
            $data = [
                'error' => $e->getMessage(),
            ];
        }

        return $data;
    }

    /**
     * Delete all keys from the current database.
     *
     * @param Redis $redis
     *
     * @return string
     * @throws RedisException
     */
    private function deleteAllKeys(Redis $redis): string {
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
     * @param Redis $redis
     *
     * @return string
     * @throws RedisException
     */
    private function deleteKey(Redis $redis): string {
        $keys = explode(',', Http::get('delete'));

        if (count($keys) === 1 && $redis->del($keys[0])) {
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } else {
            foreach ($keys as $key) {
                $redis->del($key);
            }
            $message = 'Keys has been deleted.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Get Redis info.
     *
     * @param Redis $redis
     *
     * @return array<string, mixed>
     * @throws RedisException
     */
    private function getInfo(Redis $redis): array {
        $options = ['SERVER', 'CLIENTS', 'MEMORY', 'PERSISTENCE', 'STATS', 'REPLICATION', 'CPU', 'CLASTER', 'KEYSPACE', 'COMANDSTATS'];

        $array = [];

        foreach ($options as $option) {
            $info = $redis->info($option);

            if (!empty($info)) {
                $array[$option] = $info;
            }
        }

        return $array;
    }

    /**
     * Show more info.
     *
     * @param array<int, mixed> $servers
     *
     * @return string
     */
    private function moreInfo(array $servers): string {
        try {
            $id = Http::get('moreinfo', 'int');
            $server_data = $servers[$id];
            $redis = $this->connect($server_data);

            if (isset($_GET['reset'])) {
                if ($redis->resetStat()) {
                    Helpers::alert($this->template, 'Stats has been reseted.');
                } else {
                    Helpers::alert($this->template, 'An error occurred while resetting stats.', 'bg-red-500');
                }

                $reset_link = '<a href="'.Http::queryString(['moreinfo'], ['reset' => $id]).'" class="text-red-500 hover:text-red-700 font-semibold">
                                  Reset stats
                              </a>';
            }

            return $this->template->render('partials/info_table', [
                'panel_title'    => $server_data['name'] ?? $server_data['host'].':'.$server_data['port'],
                'array'          => $this->getInfo($redis),
                'bottom_content' => method_exists($redis, 'resetStat') && isset($reset_link) ? $reset_link : '',
            ]);
        } catch (DashboardException|RedisException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get server databases.
     *
     * @param Redis $redis
     *
     * @return array<int, string>
     * @throws RedisException
     */
    private function getDatabases(Redis $redis): array {
        $databases = [];

        $db_count = (array) $redis->config('GET', 'databases');

        for ($d = 0; $d < $db_count['databases']; ++$d) {
            $keyspace = $redis->info('KEYSPACE');
            $keys_in_db = '';

            if (array_key_exists('db'.$d, $keyspace)) {
                $db = explode(',', $keyspace['db'.$d]);
                $keys = explode('=', $db[0]);
                $keys_in_db = ' ('.Helpers::formatNumber((int) $keys[1]).' keys)';
            }

            $databases[$d] = 'Database '.$d.$keys_in_db;
        }

        return $databases;
    }

    /**
     * Get all keys with data.
     *
     * @param Redis $redis
     *
     * @return array<int, array<string, string|int>>
     * @throws RedisException
     */
    private function getAllKeys(Redis $redis): array {
        static $keys = [];
        $filter = Http::get('s', 'string', '*');

        $this->template->addGlobal('search_value', $filter);

        foreach ($redis->keys($filter) as $key) {
            try {
                $type = $this->getType($redis->type($key));
            } catch (DashboardException $e) {
                $type = 'unknown';
            }

            $keys[] = [
                'key'         => $key,
                'type'        => $type,
                'ttl'         => $redis->ttl($key),
                'items_total' => $this->getCountOfItemsInKey($redis, $type, $key),
            ];
        }

        return $keys;
    }

    /**
     * Main dashboard content.
     *
     * @param Redis $redis
     *
     * @return string
     * @throws RedisException
     */
    private function mainDashboard(Redis $redis): string {
        $keys = $this->getAllKeys($redis);

        if (isset($_POST['submit_import_key'])) {
            $this->import($redis);
        }

        $paginator = new Paginator($this->template, $keys);
        $paginator->setUrl([['db', 's', 'pp'], ['p' => '']]);

        return $this->template->render('dashboards/redis/redis', [
            'databases'   => $this->getDatabases($redis),
            'current_db'  => $this->current_db,
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => $redis->dbSize(),
            'new_key_url' => Http::queryString(['db'], ['form' => 'new']),
            'edit_url'    => Http::queryString(['db', 's'], ['form' => 'edit', 'key' => '']),
            'view_url'    => Http::queryString(['db', 's'], ['view' => 'key', 'key' => '']),
            'paginator'   => $paginator->render(),
        ]);
    }

    /**
     * View key values.
     *
     * @param Redis $redis
     *
     * @return string
     * @throws RedisException
     */
    private function viewKey(Redis $redis): string {
        $key = Http::get('key');

        if (!$redis->exists($key)) {
            Http::redirect(['db']);
        }

        try {
            $type = $this->getType($redis->type($key));
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
            $items = [];

            foreach ($value as $value_key => $item) {
                [$item, $item_encode_fn, $item_is_formatted] = Helpers::decodeAndFormatValue($item);

                $items[] = [
                    'key'       => $value_key,
                    'value'     => $item,
                    'encode_fn' => $item_encode_fn,
                    'formatted' => $item_is_formatted,
                    'score'     => $type === 'zset' ? (string) $redis->zScore($key, $item) : $value_key,
                ];
            }

            $paginator = new Paginator($this->template, $items);
            $value = $paginator->getPaginated();
            $paginator->setUrl([['db', 'view', 'key', 'pp'], ['p' => '']]);
            $paginator = $paginator->render();
        } else {
            [$value, $encode_fn, $is_formatted] = Helpers::decodeAndFormatValue($value);
        }

        $ttl = $redis->ttl($key);

        return $this->template->render('partials/view_key', [
            'value'          => $value,
            'type'           => $type,
            'ttl'            => $ttl ? Helpers::formatSeconds($ttl) : null,
            'encode_fn'      => $encode_fn,
            'formatted'      => $is_formatted,
            'add_subkey_url' => Http::queryString(['db'], ['form' => 'new', 'key' => $key]),
            'deletesub_url'  => Http::queryString(['db', 'view', 'p'], ['deletesub' => 'key', 'key' => $key]),
            'edit_url'       => Http::queryString(['db'], ['form' => 'edit', 'key' => $key]),
            'export_url'     => Http::queryString(['db', 'view', 'p', 'key'], ['export' => 'key']),
            'delete_url'     => Http::queryString(['db', 'view'], ['delete' => 'key', 'key' => $key]),
            'paginator'      => $paginator,
        ]);
    }

    /**
     * Import key.
     *
     * @param Redis $redis
     *
     * @return void
     * @throws RedisException
     */
    private function import(Redis $redis): void {
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
     * @param Redis $redis
     *
     * @return string
     * @throws RedisException
     */
    private function form(Redis $redis): string {
        $key = Http::get('key', 'string', Http::post('key'));
        $type = Http::post('redis_type');
        $index = $_POST['index'] ?? '';
        $score = Http::post('score', 'int');
        $hash_key = Http::post('hash_key');
        $expire = Http::post('expire', 'int', -1);
        $encoder = Http::get('encoder', 'string', 'none');
        $value = Helpers::decodeValue(Http::post('value'), $encoder);

        if (isset($_POST['submit'])) {
            $this->saveKey($redis);
        }

        // edit|subkeys
        if (isset($_GET['key']) && $redis->exists($key)) {
            try {
                $type = $this->getType($redis->type($key));
            } catch (DashboardException $e) {
                Helpers::alert($this->template, $e->getMessage());
                $type = 'unknown';
            }
            $expire = $redis->ttl($key);
        }

        if (isset($_GET['key']) && $_GET['form'] === 'edit' && $redis->exists($key)) {
            [$value, $index, $score, $hash_key] = $this->getKeyValue($redis, $type, $key);
        }

        return $this->template->render('dashboards/redis/form', [
            'key'      => $key,
            'value'    => $value,
            'type'     => $type,
            'index'    => $index,
            'score'    => $score,
            'hash_key' => $hash_key,
            'expire'   => $expire,
            'encoders' => Helpers::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }
}
