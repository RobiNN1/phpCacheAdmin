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
    private function panels(): string {
        if (class_exists(PHPMem::class)) {
            $title = 'PHPMem v'.PHPMem::VERSION;
        }

        try {
            $info = $this->memcached->getServerStats();

            $bytes = (int) $info['bytes'];
            $max_bytes = (int) $info['limit_maxbytes'];
            $get_hits = (int) $info['get_hits'];
            $get_misses = (int) $info['get_misses'];
            $memory_usage = round(($bytes / $max_bytes) * 100, 2);
            $hit_rate = $get_hits !== 0 ? round(($get_hits / ($get_hits + $get_misses)) * 100, 2) : 0;

            $panels = [
                [
                    'title'     => $title ?? null,
                    'moreinfo'  => true,
                    'server_id' => $this->current_server,
                    'data'      => [
                        'Version'          => $info['version'],
                        'Open connections' => $info['curr_connections'],
                        'Uptime'           => Format::seconds((int) $info['uptime']),
                    ],
                ],
                [
                    'title' => 'Memory',
                    'data'  => [
                        'Total' => Format::bytes($max_bytes, 0),
                        ['Used', Format::bytes($bytes).' ('.$memory_usage.'%)', $memory_usage],
                        'Free'  => Format::bytes($max_bytes - $bytes),
                    ],
                ],
                [
                    'title' => 'Stats',
                    'data'  => [
                        'Keys'      => Format::number(count($this->all_keys)),
                        ['Hits / Misses', Format::number($get_hits).' / '.Format::number($get_misses).' (Rate '.$hit_rate.'%)', $hit_rate, 'higher'],
                        'Evictions' => Format::number((int) $info['evictions']),
                    ],
                ],
                [
                    'title' => 'Connections',
                    'data'  => [
                        'Current'  => Format::number((int) $info['curr_connections']).' / '.Format::number((int) $info['max_connections']),
                        'Total'    => Format::number((int) $info['total_connections']),
                        'Rejected' => Format::number((int) $info['rejected_connections']),
                    ],
                ],
            ];
        } catch (MemcachedException $e) {
            $panels = ['error' => $e->getMessage()];
        }

        return $this->template->render('partials/info', ['panels' => $panels, 'left' => true]);
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

        $info = $this->memcached->getKeyMeta($key);
        $ttl = $info['exp'];
        $ttl = $ttl === 0 ? -1 : $ttl;

        if (isset($_GET['export'])) {
            Helpers::export(
                [['key' => $key, 'ttl' => $ttl]],
                $key,
                fn (string $key): string => base64_encode($this->memcached->getKey($key))
            );
        }

        if (isset($_GET['delete'])) {
            $this->memcached->delete($key);
            Http::redirect();
        }

        $value = $this->memcached->getKey($key);

        [$formatted_value, $encode_fn, $is_formatted] = Value::format($value);

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $formatted_value,
            'ttl'        => Format::seconds($ttl),
            'size'       => Format::bytes($info['size']),
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
        $old_key = (string) Http::post('old_key', '');
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');

        if ($old_key !== $key) {
            $this->memcached->delete($old_key);
        }

        $this->memcached->set($key, $value, $expire);

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
     */
    private function getAllKeys(): array {
        static $keys = [];
        $search = Http::get('s', '');

        $this->template->addGlobal('search_value', $search);

        $time = time();

        foreach ($this->all_keys as $key_data) {
            $key = $key_data['key'];

            if (stripos($key, $search) !== false) {
                $ttl = $key_data['exp'] ?? null;

                $keys[] = [
                    'key'   => $key,
                    'items' => [
                        'link_title'           => $key,
                        'bytes_size'           => $key_data['size'],
                        'timediff_last_access' => $key_data['la'],
                        'ttl'                  => $ttl === -1 ? 'Doesn\'t expire' : $ttl - $time,
                    ],
                ];
            }
        }

        $keys = Helpers::sortKeys($this->template, $keys);

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
                fn (string $key, string $value, int $ttl): bool => $this->memcached->set($key, base64_decode($value), $ttl)
            );
        }

        if (isset($_POST['export_btn'])) {
            Helpers::export($keys, 'memcached_backup', fn (string $key): string => base64_encode($this->memcached->getKey($key)));
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/memcached', [
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($this->all_keys),
            'new_key_url' => Http::queryString([], ['form' => 'new']),
            'paginator'   => $paginator->render(),
            'view_key'    => Http::queryString([], ['view' => 'key', 'key' => '__key__']),
        ]);
    }
}
