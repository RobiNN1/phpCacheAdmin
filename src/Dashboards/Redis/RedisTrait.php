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
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Template;

trait RedisTrait {
    use TypesTrait;

    private Template $template;

    /**
     * Constructor.
     *
     * @param Template $template
     *
     * @return void
     */
    public function construct(Template $template): void {
        $this->template = $template;
    }

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
        } catch (DashboardException $e) {
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
        } catch (DashboardException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get server databases.
     *
     * @param Redis $redis
     *
     * @return array<int, string>
     */
    private function getDatabases(Redis $redis): array {
        $databases = [];

        if ($db_count = $redis->config('GET', 'databases')) {
            $db_count = $db_count['databases']; // @phpstan-ignore-line

            for ($d = 0; $d < $db_count; ++$d) {
                $keyspace = $redis->info('KEYSPACE');
                $keys_in_db = '';

                if (array_key_exists('db'.$d, $keyspace)) {
                    $db = explode(',', $keyspace['db'.$d]);
                    $keys = explode('=', $db[0]);
                    $keys_in_db = ' (Keys: '.Helpers::formatNumber((int) $keys[1]).')';
                }

                $databases[$d] = 'Database '.$d.$keys_in_db;
            }
        }

        return $databases;
    }

    /**
     * Get all keys with data.
     *
     * @param Redis $redis
     *
     * @return array<int, array<string, string|int>>
     */
    private function getAllKeys(Redis $redis): array {
        $keys = [];
        $filter = Http::get('s');
        $filter = !empty($filter) ? $filter : '*';

        $this->template->addTplGlobal('search_value', $filter);

        foreach ($redis->keys($filter) as $key) {
            $type = $this->getType($redis->type($key));

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
     * Get a key type.
     *
     * @param int $type
     *
     * @return string
     */
    private function getType(int $type): string {
        $data_types = [
            Redis::REDIS_STRING    => 'string',
            Redis::REDIS_SET       => 'set',
            Redis::REDIS_LIST      => 'list',
            Redis::REDIS_ZSET      => 'zset',
            Redis::REDIS_HASH      => 'hash',
            Redis::REDIS_NOT_FOUND => 'other',
        ];

        return $data_types[$type];
    }

    /**
     * Main dashboard content.
     *
     * @param Redis $redis
     *
     * @return string
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
     */
    private function viewKey(Redis $redis): string {
        $key = Http::get('key');

        if (!$redis->exists($key)) {
            Http::redirect(['db']);
        }

        $type = $this->getType($redis->type($key));

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
     */
    private function form(Redis $redis): string {
        $key = Http::get('key');
        $type = 'string';
        $value = '';
        $index = null;
        $score = 0;
        $hash_key = '';
        $expire = -1;

        $encoder = Http::get('encoder');
        $encoder = !empty($encoder) ? $encoder : 'none';

        $this->saveKey($redis);

        if (isset($_GET['key']) && $_GET['form'] === 'edit' && $redis->exists($key)) {
            $type = $this->getType($redis->type($key));
            $expire = $redis->ttl($key);
            [$value, $index, $score, $hash_key] = $this->getKeyValue($redis, $type, $key);
        }

        // subkeys
        if (isset($_GET['key']) && $_GET['form'] === 'new' && $redis->exists($key)) {
            $type = $this->getType($redis->type($key));
            $expire = $redis->ttl($key);
        }

        if ($encoder !== 'none') {
            $value = Helpers::decodeValue($value, $encoder);
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
