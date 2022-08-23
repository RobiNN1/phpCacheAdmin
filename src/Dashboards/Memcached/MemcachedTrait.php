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
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait MemcachedTrait {
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
            $memcached = $this->connect($servers[Http::get('panel', 'int')]);
            $server_info = $memcached->getServerStats();

            return [
                'Version'          => $server_info['version'],
                'Open connections' => $server_info['curr_connections'],
                'Uptime'           => Format::seconds((int) $server_info['uptime']),
                'Cache limit'      => Format::bytes((int) $server_info['limit_maxbytes']),
                'Used'             => Format::bytes((int) $server_info['bytes']),
                'Keys'             => Format::number(count($memcached->getKeys())),
            ];
        } catch (DashboardException $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
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

        if (count($keys) === 1 && @$memcached->get($keys[0]) !== false) {
            $memcached->delete($keys[0]);
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } elseif (count($keys) > 1) {
            foreach ($keys as $key) {
                $memcached->delete($key);
            }
            $message = 'Keys has been deleted.';
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

            $info = $this->connect($server_data)->getServerStats();
            $memcached = extension_loaded('memcached') ? 'd' : '';

            $info += Helpers::getExtIniInfo('memcache'.$memcached);

            return $this->template->render('partials/info_table', [
                'panel_title' => $server_data['name'] ?? $server_data['host'].':'.$server_data['port'],
                'array'       => Helpers::convertBoolToString($info),
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
        static $keys = [];

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

        if ($memcached->get($key) === false) {
            Http::redirect();
        }

        if (isset($_GET['export'])) {
            header('Content-disposition: attachment; filename='.$key.'.txt');
            header('Content-Type: text/plain');
            echo $memcached->get($key);
            exit;
        }

        if (isset($_GET['delete'])) {
            $memcached->delete($key);
            Http::redirect(['db']);
        }

        $value = $memcached->get($key);

        [$value, $encode_fn, $is_formatted] = Helpers::decodeAndFormatValue($value);

        $ttl = Http::get('ttl', 'int');
        $ttl = $ttl === 0 ? -1 : $ttl;

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $value,
            'type'       => 'string', // In Memcache(d) everything is stored as a string.
            'ttl'        => Format::seconds($ttl),
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
            'delete_url' => Http::queryString(['view'], ['delete' => 'key', 'key' => $key]),
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

            if ($memcached->get($key_name) === false) {
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
        $expire = Http::get('ttl', 'int');
        $encoder = Http::get('encoder', 'string', 'none');
        $value = Helpers::decodeValue(Http::post('value'), $encoder);

        if (isset($_GET['key']) && $memcached->get($key)) {
            $value = $memcached->get($key);
        }

        if (isset($_POST['submit'])) {
            $key = Http::post('key');
            $expire = Http::post('expire', 'int');
            $old_key = Http::post('old_key');
            $encoder = Http::post('encoder');
            $value = Helpers::encodeValue($value, $encoder);

            if ($old_key !== '' && $old_key !== $key) {
                $memcached->delete($old_key);
            }

            $memcached->store($key, $value, $expire);

            Http::redirect([], ['view' => 'key', 'ttl' => $expire, 'key' => $key]);
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
