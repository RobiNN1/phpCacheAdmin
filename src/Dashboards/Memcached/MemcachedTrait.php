<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
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
    /**
     * @param array<int, mixed> $all_keys
     */
    private function panels(array $all_keys): string {
        if (extension_loaded('memcached') || extension_loaded('memcache')) {
            $memcached = extension_loaded('memcached') ? 'd' : '';
            $title = 'PHP Memcache'.$memcached.' extension <span>v'.phpversion('memcache'.$memcached).'</span>';
        } elseif (class_exists(Compatibility\PHPMem::class)) {
            $title = 'PHPMem <span>v'.Compatibility\PHPMem::VERSION.'</span>';
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
                        'Cache limit' => Format::bytes((int) $server_info['limit_maxbytes'], 0),
                        'Used'        => Format::bytes((int) $server_info['bytes']),
                        'Keys'        => Format::number(count($all_keys)), // Keys are loaded via sockets and not extension itself
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
            return Helpers::alert($this->template, 'All keys have been removed.', 'success');
        }

        return Helpers::alert($this->template, 'An error occurred while deleting all keys.', 'error');
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

        [$formatted_value, $encode_fn, $is_formatted] = Value::format($value);

        $ttl = Http::get('ttl', 0);
        $ttl = $ttl === 0 ? -1 : $ttl;

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $formatted_value,
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
     * @param array<int, mixed> $all_keys
     *
     * @return array<int, array<string, string|int>>
     */
    private function getAllKeys(array $all_keys): array {
        static $keys = [];
        $search = Http::get('s', '');

        $this->template->addGlobal('search_value', $search);

        foreach ($all_keys as $key_data) {
            $key = $key_data['key'] ?? $key_data;

            if ($search === '' || stripos($key, $search) !== false) {
                $ttl = $key_data['exp'] ?? null;

                $keys[] = [
                    'key'   => $key,
                    'ttl'   => $ttl,
                    'items' => [
                        'link_title'       => $key,
                        'time_last_access' => $key_data['la'],
                        'ttl'              => $ttl === -1 ? 'Doesn\'t expire' : $ttl,
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
        $all_keys = $this->memcached->getKeys();
        $keys = $this->getAllKeys($all_keys);

        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                fn (string $key): bool => $this->memcached->exists($key),
                fn (string $key, string $value, int $expire): bool => $this->memcached->store($key, $value, $expire)
            );
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/memcached', [
            'select'      => Helpers::serverSelector($this->template, $this->servers, $this->current_server),
            'panels'      => $this->panels($all_keys),
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($all_keys),
            'new_key_url' => Http::queryString([], ['form' => 'new']),
            'paginator'   => $paginator->render(),
            'view_key'    => Http::queryString([], ['view' => 'key', 'ttl' => '__ttl__', 'key' => '__key__']),
        ]);
    }
}
