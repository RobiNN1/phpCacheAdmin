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
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait MemcachedTrait {
    private function panels(): string {
        if (extension_loaded('memcached') || extension_loaded('memcache')) {
            $memcached = extension_loaded('memcached') ? 'd' : '';
            $title = 'PHP Memcache'.$memcached.' extension <b>v'.phpversion('memcache'.$memcached).'</b>';
        } elseif (class_exists(Compatibility\PHPMem::class)) {
            $title = 'PHPMem <b>v'.Compatibility\PHPMem::VERSION.'</b>';
        }

        try {
            $server_info = $this->memcached->getServerStats();

            $panels = [
                [
                    'title'     => $title ?? null,
                    'moreinfo'  => true,
                    'server_id' => $this->current_server,
                    'data'      => [
                        'Version'          => $server_info['version'],
                        'Open connections' => $server_info['curr_connections'],
                        'Uptime'           => Format::seconds((int) $server_info['uptime']),
                    ],
                ],
                [
                    'title' => 'Stats',
                    'data'  => [
                        'Cache limit' => Format::bytes((int) $server_info['limit_maxbytes']),
                        'Used'        => Format::bytes((int) $server_info['bytes']),
                        'Keys'        => Format::number(count($this->memcached->getKeys())), // Keys are loaded via sockets and not extension itself
                    ],
                ],
            ];
        } catch (MemcachedException $e) {
            $panels = ['error' => $e->getMessage()];
        }

        return $this->template->render('partials/info', ['panels' => $panels]);
    }

    /**
     * @throws MemcachedException
     */
    private function deleteAllKeys(): string {
        if ($this->memcached->flush()) {
            $message = 'All keys have been removed.';
        } else {
            $message = 'An error occurred while deleting all keys.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    private function moreInfo(): string {
        try {
            $info = $this->memcached->getServerStats();

            if (extension_loaded('memcached') || extension_loaded('memcache')) {
                $memcached = extension_loaded('memcached') ? 'd' : '';
                $info += Helpers::getExtIniInfo('memcache'.$memcached);
            }

            return $this->template->render('partials/info_table', [
                'panel_title' => Helpers::getServerTitle($this->servers[$this->current_server]),
                'array'       => Helpers::convertTypesToString($info),
            ]);
        } catch (MemcachedException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @throws MemcachedException
     */
    private function viewKey(): string {
        $key = Http::get('key', '');

        if (!$this->memcached->exists($key)) {
            Http::redirect();
        }

        if (isset($_GET['export'])) {
            Helpers::export($key, $this->memcached->getKey($key));
        }

        if (isset($_GET['delete'])) {
            $this->memcached->delete($key);
            Http::redirect();
        }

        $value = $this->memcached->getKey($key);

        [$value, $encode_fn, $is_formatted] = Value::format($value);

        $ttl = Http::get('ttl', 0);
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
     * @throws MemcachedException
     */
    public function saveKey(): void {
        $key = Http::post('key', '');
        $expire = Http::post('expire', 0);
        $old_key = Http::post('old_key', '');
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');

        if ($old_key !== '' && $old_key !== $key) {
            $this->memcached->delete($old_key);
        }

        $this->memcached->store($key, $value, $expire);

        Http::redirect([], ['view' => 'key', 'ttl' => $expire, 'key' => $key]);
    }

    /**
     * Add/edit form.
     *
     * @throws MemcachedException
     */
    private function form(): string {
        $key = Http::get('key', '');
        $expire = Http::get('ttl', 0);
        $expire = $expire === -1 ? 0 : $expire;

        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');

        if (isset($_GET['key']) && $this->memcached->exists($key)) {
            $value = $this->memcached->getKey($key);
        }

        if (isset($_POST['submit'])) {
            $this->saveKey();
        }

        $value = Value::converter($value, $encoder, 'view');

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
     * @return array<int, array<string, string|int>>
     *
     * @throws MemcachedException
     */
    private function getAllKeys(): array {
        static $keys = [];
        $search = Http::get('s', '');

        $this->template->addGlobal('search_value', $search);

        foreach ($this->memcached->getKeys() as $key_data) {
            $key = $key_data['key'] ?? $key_data;

            if (empty($search) || stripos($key, $search) !== false) {
                $ttl = $key_data['exp'] ?? null;

                $keys[] = [
                    'key'   => $key,
                    'ttl'   => $ttl,
                    'items' => [
                        'link_title' => $key,
                        'type'       => 'string', // In Memcached everything is stored as a string. Calling gettype() will slow down page loading.
                        'ttl'        => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
                    ],
                ];
            }
        }

        return $keys;
    }

    /**
     * @throws MemcachedException
     */
    private function mainDashboard(): string {
        $keys = $this->getAllKeys();

        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                fn (string $key): bool => $this->memcached->exists($key),
                fn (string $key, string $value, int $expire): bool => $this->memcached->store($key, $value, $expire)
            );
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/memcached', [
            'select'      => Helpers::serverSelector($this->template, $this->servers, $this->current_server),
            'panels'      => $this->panels(),
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($this->memcached->getKeys()),
            'new_key_url' => Http::queryString([], ['form' => 'new']),
            'paginator'   => $paginator->render(),
            'view_key'    => Http::queryString([], ['view' => 'key', 'ttl' => '__ttl__', 'key' => '__key__']),
        ]);
    }
}
