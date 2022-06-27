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

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Admin;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Memcached\MemcacheCompatibility\Memcache;
use RobiNN\Pca\Dashboards\Memcached\MemcacheCompatibility\Memcached;
use RobiNN\Pca\Helpers;

trait MemcachedTrait {
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
            $server_info = $connect->getServerStats();

            if (!empty($server_info['version'])) {
                $data = [
                    'Version'          => $server_info['version'],
                    'Open connections' => $server_info['curr_connections'],
                    'Uptime'           => Helpers::formatSeconds((int) $server_info['uptime']),
                    'Cache limit'      => Helpers::formatBytes((int) $server_info['limit_maxbytes']),
                    'Used'             => Helpers::formatBytes((int) $server_info['bytes']),
                    'Keys'             => count($connect->getKeys()),
                ];
            } else {
                $data = [
                    'error' => 'Failed to get server information.',
                ];
            }
        } catch (DashboardException $e) {
            $data = [
                'error' => $e->getMessage(),
            ];
        }

        return $data;
    }

    /**
     * Delete all keys.
     *
     * @param Memcache|Memcached $connect
     *
     * @return string
     */
    private function deleteAllKeys($connect): string {
        if ($connect->flush()) {
            $message = 'All keys have been removed.';
        } else {
            $message = 'An error occurred while deleting all keys.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Delete key or selected keys.
     *
     * @param Memcache|Memcached $connect
     *
     * @return string
     */
    private function deleteKey($connect): string {
        $keys = explode(',', Admin::get('delete'));

        if (count($keys) === 1) {
            $connect->delete($keys[0]);
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } else {
            foreach ($keys as $key) {
                $connect->delete($key);
            }
            $message = 'Keys has been deleted.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
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
            $id = Admin::get('moreinfo', 'int');
            $server_data = $servers[$id];

            return $this->template->render('partials/info_table', [
                'panel_title' => $server_data['name'] ?? $server_data['host'].':'.$server_data['port'],
                'array'       => $this->connect($server_data)->getServerStats(),
            ]);
        } catch (DashboardException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get all keys with data.
     *
     * @param Memcache|Memcached $connect
     *
     * @return array
     */
    private function getAllKeys($connect): array {
        $keys = [];

        foreach ($connect->getKeys() as $key) {
            $keys[] = [
                'key'  => $key,
                'type' => gettype($connect->get($key)),
            ];
        }

        return $keys;
    }

    /**
     * Main dashboard content.
     *
     * @param Memcache|Memcached $connect
     *
     * @return string
     */
    private function mainDashboard($connect): string {
        $all_keys = $this->getAllKeys($connect);
        $keys = $all_keys; // variable for paginated data

        [$pages, $page, $per_page] = Admin::paginate($keys);

        $memcached = extension_loaded('memcached') ? 'd' : '';

        return $this->template->render('dashboards/memcached', [
            'show_info'         => !isset($_GET['moreinfo']),
            'title'             => 'Memcache'.$memcached,
            'extension_version' => phpversion('memcache'.$memcached),
            'info'              => $this->info(),
            'keys'              => $keys,
            'all_keys'          => $all_keys,
            'current_page'      => $page,
            'paginate'          => $pages,
            'paginate_url'      => Admin::queryString(['pp'], ['p' => '']),
            'per_page'          => $per_page,
            'new_key_url'       => Admin::queryString([], ['form' => 'new']),
            'view_url'          => Admin::queryString([], ['view' => 'key', 'key' => '']),
            'edit_url'          => Admin::queryString([], ['form' => 'edit', 'key' => '']),
        ]);
    }

    /**
     * View key value.
     *
     * @param Memcache|Memcached $connect
     *
     * @return string
     */
    private function viewKey($connect): string {
        $key = Admin::get('key');

        return $this->template->render('partials/view_key', [
            'value'    => $connect->get($key),
            'type'     => 'string',
            'edit_url' => Admin::queryString(['db'], ['form' => 'edit', 'key' => $key]),
        ]);
    }

    /**
     * Add/edit form.
     *
     * @param Memcache|Memcached $connect
     *
     * @return string
     */
    private function form($connect): string {
        $key = Admin::get('key');
        $value = '';

        if (isset($_GET['key']) && $connect->get($key)) {
            $value = $connect->get($key);
        }

        if (isset($_POST['submit'])) {
            $key = Admin::post('key');
            $value = Admin::post('value');

            $connect->set($key, $value);

            Admin::redirect(Admin::queryString());
        }

        return $this->template->render('partials/memcached_form', [
            'key'   => $key,
            'value' => $value,
        ]);
    }
}
