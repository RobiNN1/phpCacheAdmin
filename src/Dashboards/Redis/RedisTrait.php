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
use RobiNN\Pca\Admin;
use RobiNN\Pca\Dashboards\DashboardException;

trait RedisTrait {
    use GetValueTrait;
    use RedisFormTrait;

    /**
     * Get server info for ajax.
     * This allows loading data of each server separately.
     *
     * @param array $servers
     *
     * @return array
     */
    private function serverInfo(array $servers): array {
        try {
            $connect = $this->connect($servers[Admin::get('panel', 'int')]);
            $server_info = $connect->info();

            $all_keys = 0;

            foreach ($server_info as $key => $value) {
                if (str_starts_with($key, 'db')) {
                    $db = explode(',', $value);
                    $keys = explode('=', $db[0]);
                    $all_keys += (int) $keys[1];
                }
            }

            $data = [
                'Version'           => $server_info['redis_version'],
                'Connected clients' => $server_info['connected_clients'],
                'Uptime'            => Admin::formatSeconds($server_info['uptime_in_seconds']),
                'Memory used'       => Admin::formatSize($server_info['used_memory']),
                'Keys'              => $all_keys.' (all databases)',
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
     * @param Redis $connect
     *
     * @return string
     */
    private function deleteAllKeys(Redis $connect): string {
        if ($connect->flushDB()) {
            return $this->template->render('components/alert', [
                'message' => 'All keys from the current database have been removed.',
            ]);
        }

        return '';
    }

    /**
     * Delete selected keys.
     *
     * @param Redis $connect
     *
     * @return string
     */
    private function deleteKeys(Redis $connect): string {
        $keys = filter_input(INPUT_GET, 'delete', FILTER_UNSAFE_RAW);
        $keys = explode(',', $keys);

        if (count($keys) === 1 && $connect->del($keys[0])) {
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } else {
            foreach ($keys as $key) {
                $connect->del($key);
            }
            $message = 'Keys has been deleted.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Get Redis info.
     *
     * @param Redis $connect
     *
     * @return array
     */
    private function getInfo(Redis $connect): array {
        $options = ['SERVER', 'CLIENTS', 'MEMORY', 'PERSISTENCE', 'STATS', 'REPLICATION', 'CPU', 'CLASTER', 'KEYSPACE', 'COMANDSTATS'];

        $array = [];

        foreach ($options as $option) {
            $info = $connect->info($option);

            if (!empty($info)) {
                $array[$option] = $info;
            }
        }

        return $array;
    }

    /**
     * Show more info.
     *
     * @param array $servers
     *
     * @return string
     */
    private function moreInfo(array $servers): string {
        try {
            $id = filter_input(INPUT_GET, 'moreinfo');
            $server_data = $servers[$id];
            $connect = $this->connect($server_data);

            if (isset($_GET['reset'])) {
                if ($connect->resetStat()) {
                    Admin::alert($this->template, 'Stats has been reseted.');
                } else {
                    Admin::alert($this->template, 'An error occurred while resetting stats.', 'bg-red-500');
                }

                $reset_link = '<a href="'.Admin::queryString(['moreinfo'], ['reset' => $id]).'" class="text-red-500 hover:text-red-700 font-semibold">Reset stats</a>';
            }

            return $this->template->render('partials/info_table', [
                'panel_title'    => $server_data['name'] ?? $server_data['host'].':'.$server_data['port'],
                'array'          => $this->getInfo($connect),
                'bottom_content' => method_exists($connect, 'resetStat') && isset($reset_link) ? $reset_link : '',
            ]);
        } catch (DashboardException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get server databases.
     *
     * @param Redis $connect
     *
     * @return array
     */
    private function getDatabases(Redis $connect): array {
        $databases = [];

        if ($db_count = $connect->config('GET', 'databases')) {
            $db_count = $db_count['databases']; // @phpstan-ignore-line

            for ($d = 0; $d < $db_count; ++$d) {
                $keyspace = $connect->info('KEYSPACE');
                $keys_in_db = '';

                if (array_key_exists('db'.$d, $keyspace)) {
                    $db = explode(',', $keyspace['db'.$d]);
                    $keys = explode('=', $db[0]);
                    $keys_in_db = ' (Keys: '.$keys[1].')';
                }

                $databases[$d] = 'Database '.$d.$keys_in_db;
            }
        }

        return $databases;
    }

    /**
     * Get keys with values.
     *
     * @param Redis $connect
     *
     * @return array
     */
    private function getKeys(Redis $connect): array {
        $keys = [];
        $filter = Admin::get('s');
        $filter = !empty($filter) ? $filter : '*';

        $this->template->addTplGlobal('search_value', $filter);

        foreach ($connect->keys($filter) as $key) {
            $type = $this->getType($connect->type($key));

            $keys[] = [
                'key'   => $key,
                'type'  => $type,
                'ttl'   => $connect->ttl($key),
                'items' => $this->getItemsInKey($connect, $type, $key),
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
     * Get a number of items in a key.
     *
     * @param Redis  $connect
     * @param string $type
     * @param string $key
     *
     * @return int|null
     */
    private function getItemsInKey(Redis $connect, string $type, string $key): ?int {
        switch ($type) {
            case 'set':
                $items = $connect->sCard($key);
                break;
            case 'list':
                $items = $connect->lLen($key);
                break;
            case 'zset':
                $items = $connect->zCard($key);
                break;
            case 'hash':
                $items = $connect->hLen($key);
                break;
            default:
                $items = null;
        }

        return $items;
    }

    /**
     * Main dashboard content.
     *
     * @param Redis $connect
     *
     * @return string
     */
    private function mainDashboard(Redis $connect): string {
        $keys = $this->getKeys($connect);

        [$pages, $page, $per_page] = Admin::paginate($keys);

        return $this->template->render('dashboards/redis', [
            'show_info'         => !isset($_GET['moreinfo']),
            'title'             => 'Redis',
            'extension_version' => phpversion('redis'),
            'info'              => $this->info(),
            'databases'         => $this->getDatabases($connect),
            'current_db'        => $this->current_db,
            'keys'              => $keys,
            'all_keys'          => $connect->dbSize(),
            'current_page'      => $page,
            'paginate'          => $pages,
            'paginate_url'      => Admin::queryString(['db', 's', 'pp'], ['p' => '']),
            'per_page'          => $per_page,
            'new_key_url'       => Admin::queryString(['db'], ['form' => 'new']),
            'view_url'          => Admin::queryString([], ['view' => 'key', 'key' => '']),
            'edit_url'          => Admin::queryString([], ['form' => 'edit', 'key' => '']),
        ]);
    }

    /**
     * Redis add/edit a form.
     *
     * @param Redis $connect
     *
     * @return string
     */
    private function form(Redis $connect): string {
        $key = Admin::get('key');
        $type = 'string';
        $value = '';
        $index = null;
        $score = 0;
        $hash_key = '';
        $expire = -1;

        $this->saveKey($connect);

        if (isset($_GET['key']) && $_GET['form'] === 'edit' && $connect->exists($key)) {
            $type = $this->getType($connect->type($key));
            $expire = $connect->ttl($key);
            [$value, $index, $score, $hash_key] = $this->getKeyValue($connect, $type, $key);
        }

        if (isset($_GET['key']) && $_GET['form'] === 'new' && $connect->exists($key)) {
            $type = $this->getType($connect->type($key));
            $expire = $connect->ttl($key);
        }

        return $this->template->render('partials/redis_form', [
            'key'      => $key,
            'value'    => $value,
            'type'     => $type,
            'index'    => $index,
            'score'    => $score,
            'hash_key' => $hash_key,
            'expire'   => $expire,
        ]);
    }
}
