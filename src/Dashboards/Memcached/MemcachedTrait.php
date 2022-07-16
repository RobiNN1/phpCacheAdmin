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

use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Memcached\MemcacheCompatibility\Memcache;
use RobiNN\Pca\Dashboards\Memcached\MemcacheCompatibility\Memcached;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait MemcachedTrait {
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
            $memcached = $this->connect($servers[Http::get('panel', 'int')]);
            $server_info = $memcached->getServerStats();

            if (!empty($server_info['version'])) {
                $data = [
                    'Version'          => $server_info['version'],
                    'Open connections' => $server_info['curr_connections'],
                    'Uptime'           => Helpers::formatSeconds((int) $server_info['uptime']),
                    'Cache limit'      => Helpers::formatBytes((int) $server_info['limit_maxbytes']),
                    'Used'             => Helpers::formatBytes((int) $server_info['bytes']),
                    'Keys'             => Helpers::formatNumber(count($memcached->getKeys())),
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
     * @param Memcache|Memcached $memcached
     *
     * @return string
     */
    private function deleteAllKeys($memcached): string {
        if ($memcached->flush()) {
            $message = 'All keys have been removed.';
        } else {
            $message = 'An error occurred while deleting all keys.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Delete key or selected keys.
     *
     * @param Memcache|Memcached $memcached
     *
     * @return string
     */
    private function deleteKey($memcached): string {
        $keys = explode(',', Http::get('delete'));

        if (count($keys) === 1) {
            $memcached->delete($keys[0]);
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } else {
            foreach ($keys as $key) {
                $memcached->delete($key);
            }
            $message = 'Keys has been deleted.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
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
     * @param Memcache|Memcached $memcached
     *
     * @return array<int, array<string, string|int>>
     */
    private function getAllKeys($memcached): array {
        $keys = [];

        foreach ($memcached->getKeys() as $key_data) {
            $keys[] = [
                'key'  => $key_data['key'],
                'ttl'  => $key_data['exp'],
                'type' => 'string', // In Memcache(d) everything is stored as a string.
            ];
        }

        return $keys;
    }

    /**
     * Main dashboard content.
     *
     * @param Memcache|Memcached $memcached
     *
     * @return string
     */
    private function mainDashboard($memcached): string {
        $keys = $this->getAllKeys($memcached);

        if (isset($_POST['submit_import_key'])) {
            $this->import($memcached);
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/memcached/memcached', [
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($keys),
            'new_key_url' => Http::queryString([], ['form' => 'new']),
            'edit_url'    => Http::queryString([], ['form' => 'edit', 'ttl' => 'ttl_value', 'key' => '']),
            'view_url'    => Http::queryString([], ['view' => 'key', 'ttl' => 'ttl_value', 'key' => '']),
            'paginator'   => $paginator->render(),
        ]);
    }

    /**
     * View key value.
     *
     * @param Memcache|Memcached $memcached
     *
     * @return string
     */
    private function viewKey($memcached): string {
        $key = Http::get('key');

        if (empty($memcached->get($key))) {
            Http::redirect();
        }

        if (isset($_GET['export'])) {
            header('Content-disposition: attachment; filename='.$key.'.txt');
            header('Content-Type: text/plain');
            echo $memcached->get($key);
            exit;
        }

        $value = $memcached->get($key);

        [$value, $encode_fn, $is_formatted] = Helpers::decodeAndFormatValue($value);

        $ttl = Http::get('ttl', 'int');

        return $this->template->render('partials/view_key', [
            'value'      => $value,
            'type'       => 'string', // In Memcache(d) everything is stored as a string.
            'ttl'        => !empty($ttl) ? Helpers::formatSeconds($ttl) : null,
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
        ]);
    }

    /**
     * Import key.
     *
     * @param Memcache|Memcached $memcached
     *
     * @return void
     */
    private function import($memcached): void {
        if ($_FILES['import']['type'] === 'text/plain') {
            $key_name = Http::post('key_name');

            if (empty($memcached->get($key_name))) {
                $value = file_get_contents($_FILES['import']['tmp_name']);

                $memcached->store($key_name, $value, Http::post('expire', 'int'));

                Http::redirect();
            }
        }
    }

    /**
     * Add/edit form.
     *
     * @param Memcache|Memcached $memcached
     *
     * @return string
     */
    private function form($memcached): string {
        $key = Http::get('key');
        $value = '';
        $expire = 0;

        $encoder = Http::get('encoder');
        $encoder = !empty($encoder) ? $encoder : 'none';

        if (isset($_GET['key']) && $memcached->get($key)) {
            $value = $memcached->get($key);
            $expire = Http::get('ttl', 'int');
            $expire = $expire === -1 ? 0 : $expire;
        }

        if (isset($_POST['submit'])) {
            $key = Http::post('key');
            $value = Http::post('value');
            $expire = Http::post('expire', 'int');
            $old_key = Http::post('old_key');
            $encoder = Http::post('encoder');

            if ($encoder !== 'none') {
                $value = Helpers::encodeValue($value, $encoder);
            }

            if (!empty($old_key) && $old_key !== $key) {
                $memcached->delete($old_key);
            }

            $memcached->store($key, $value, $expire);

            Http::redirect([], ['view' => 'key', 'ttl' => $expire, 'key' => $key]);
        }

        if ($encoder !== 'none') {
            $value = Helpers::decodeValue($value, $encoder);
        }

        return $this->template->render('dashboards/memcached/form', [
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Helpers::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }
}
