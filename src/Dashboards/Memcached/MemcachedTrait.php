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

use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait MemcachedTrait {
    /**
     * Get server info for ajax.
     *
     * @return array<string, mixed>
     */
    private function serverInfo(): array {
        try {
            $memcached = $this->connect($this->servers[Http::get('panel', 'int')]);
            $server_info = $memcached->getServerStats();

            // Keys are loaded via sockets and not extension itself.
            try {
                $keys = Format::number(count($memcached->getKeys()));
            } catch (MemcachedException $e) {
                $keys = 'An error occurred while retrieving keys.';
            }

            return [
                'Version'          => $server_info['version'],
                'Open connections' => $server_info['curr_connections'],
                'Uptime'           => Format::seconds((int) $server_info['uptime']),
                'Cache limit'      => Format::bytes((int) $server_info['limit_maxbytes']),
                'Used'             => Format::bytes((int) $server_info['bytes']),
                'Keys'             => $keys,
            ];
        } catch (DashboardException|MemcachedException $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @throws MemcachedException
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
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @throws MemcachedException
     */
    private function deleteKey($memcached): string {
        return Helpers::deleteKey($this->template, static fn (string $key): bool => $memcached->delete($key));
    }

    private function moreInfo(): string {
        try {
            $id = Http::get('moreinfo', 'int');
            $server_data = $this->servers[$id];

            $info = $this->connect($server_data)->getServerStats();

            if (extension_loaded('memcached') || extension_loaded('memcache')) {
                $memcached = extension_loaded('memcached') ? 'd' : '';
                $info += Helpers::getExtIniInfo('memcache'.$memcached);
            }

            return $this->template->render('partials/info_table', [
                'panel_title' => $server_data['name'] ?? $server_data['host'].':'.$server_data['port'],
                'array'       => Helpers::convertBoolToString($info),
            ]);
        } catch (DashboardException|MemcachedException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @throws MemcachedException
     */
    private function viewKey($memcached): string {
        $key = Http::get('key');

        if (!$memcached->exists($key)) {
            Http::redirect();
        }

        if (isset($_GET['export'])) {
            header('Content-disposition: attachment; filename='.$key.'.txt');
            header('Content-Type: text/plain');
            echo $memcached->getKey($key);
            exit;
        }

        if (isset($_GET['delete'])) {
            $memcached->delete($key);
            Http::redirect();
        }

        $value = $memcached->getKey($key);

        [$value, $encode_fn, $is_formatted] = Value::format($value);

        $ttl = Http::get('ttl', 'int');
        $ttl = $ttl === 0 ? -1 : $ttl;

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $value,
            'type'       => null,
            'ttl'        => Format::seconds($ttl),
            'size'       => Format::bytes(strlen($value)),
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
            'delete_url' => Http::queryString(['view'], ['delete' => 'key', 'key' => $key]),
        ]);
    }

    /**
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @throws MemcachedException
     */
    private function saveKey($memcached): void {
        $key = Http::post('key');
        $expire = Http::post('expire', 'int');
        $old_key = Http::post('old_key');
        $value = Value::encode(Http::post('value'), Http::post('encoder'));

        if ($old_key !== '' && $old_key !== $key) {
            $memcached->delete($old_key);
        }

        $memcached->store($key, $value, $expire);

        Http::redirect([], ['view' => 'key', 'ttl' => $expire, 'key' => $key]);
    }

    /**
     * Add/edit form.
     *
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @throws MemcachedException
     */
    private function form($memcached): string {
        $key = Http::get('key');
        $expire = Http::get('ttl', 'int');
        $expire = $expire === -1 ? 0 : $expire;

        $encoder = Http::get('encoder', 'string', 'none');
        $value = Http::post('value');

        if (isset($_GET['key']) && $memcached->exists($key)) {
            $value = $memcached->getKey($key);
        }

        if (isset($_POST['submit'])) {
            $this->saveKey($memcached);
        }

        $value = Value::decode($value, $encoder);

        return $this->template->render('partials/form', [
            'exp_attr' => ' min="0" max="2592000"',
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }

    /**
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @return array<int, array<string, string|int>>
     * @throws MemcachedException
     */
    private function getAllKeys($memcached): array {
        static $keys = [];

        foreach ($memcached->getKeys() as $key_data) {
            $key = $key_data['key'] ?? $key_data;
            $ttl = $key_data['exp'] ?? null;

            $keys[] = [
                'key'   => $key,
                'items' => [
                    'title' => [
                        'title' => $key,
                        'link'  => Http::queryString([], ['view' => 'key', 'ttl' => $ttl, 'key' => $key]),
                    ],
                    'type'  => 'string', // In Memcached everything is stored as a string. Calling gettype() will slow down page loading.
                    'ttl'   => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
                ],
            ];
        }

        return $keys;
    }

    /**
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @throws MemcachedException
     */
    private function import($memcached): void {
        if ($_FILES['import']['type'] === 'text/plain') {
            $key_name = Http::post('key_name');

            if (!$memcached->exists($key_name)) {
                $value = file_get_contents($_FILES['import']['tmp_name']);

                $memcached->store($key_name, $value, Http::post('expire', 'int'));

                Http::redirect();
            }
        }
    }

    /**
     * @param Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem $memcached
     *
     * @throws MemcachedException
     */
    private function mainDashboard($memcached): string {
        $keys = $this->getAllKeys($memcached);

        if (isset($_POST['submit_import_key'])) {
            $this->import($memcached);
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/memcached', [
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($keys),
            'new_key_url' => Http::queryString([], ['form' => 'new']),
            'paginator'   => $paginator->render(),
        ]);
    }
}
