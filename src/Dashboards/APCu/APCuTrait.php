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

namespace RobiNN\Pca\Dashboards\APCu;

use RobiNN\Pca\Config;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait APCuTrait {
    /**
     * @return array<int, mixed>
     */
    private function panels(): array {
        $info = apcu_cache_info(true);
        $memory_info = apcu_sma_info(true);

        $total_memory = $memory_info['num_seg'] * $memory_info['seg_size'];
        $memory_used = ($memory_info['num_seg'] * $memory_info['seg_size']) - $memory_info['avail_mem'];

        $hit_rate = (int) $info['num_hits'] !== 0 ? $info['num_hits'] / ($info['num_hits'] + $info['num_misses']) : 0;

        return [
            [
                'title'    => 'Status',
                'moreinfo' => true,
                'data'     => [
                    'Start time'       => Format::time($info['start_time']),
                    'Cache full count' => $info['expunges'],
                ],
            ],
            [
                'title' => 'Memory',
                'data'  => [
                    'Total' => Format::bytes((int) $total_memory),
                    'Used'  => Format::bytes((int) $memory_used),
                    'Free'  => Format::bytes((int) $memory_info['avail_mem']),
                ],
            ],
            [
                'title' => 'Stats',
                'data'  => [
                    'Cached scripts' => $info['num_entries'],
                    'Hits'           => Format::number((int) $info['num_hits']),
                    'Misses'         => Format::number((int) $info['num_misses']),
                    'Hit rate'       => round($hit_rate * 100).'%',
                ],
            ],
        ];
    }

    private function moreInfo(): string {
        $info = apcu_cache_info(true);

        foreach (apcu_sma_info(true) as $mem_name => $mem_value) {
            if (!is_array($mem_value)) {
                $info['memory'][$mem_name] = $mem_value;
            }
        }

        $info += Helpers::getExtIniInfo('apcu');

        return $this->template->render('partials/info_table', [
            'panel_title' => 'APCu Info',
            'array'       => Helpers::convertBoolToString($info),
        ]);
    }

    private function viewKey(): string {
        $key = Http::get('key', '');

        if (!apcu_exists($key)) {
            Http::redirect();
        }

        $value = Helpers::mixedToString(apcu_fetch($key));

        if (isset($_GET['export'])) {
            Helpers::export($key, $value);
        }

        if (isset($_GET['delete'])) {
            apcu_delete($key);
            Http::redirect();
        }

        [$value, $encode_fn, $is_formatted] = Value::format($value);

        $key_data = apcu_key_info($key);

        $ttl = $key_data['ttl'] === 0 ? -1 : $key_data['creation_time'] + $key_data['ttl'] - time();

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $value,
            'type'       => 'string', // Checking the original data type with gettype() can affect performance.
            'ttl'        => Format::seconds($ttl),
            'size'       => Format::bytes(strlen($value)),
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
            'delete_url' => Http::queryString(['view'], ['delete' => 'key', 'key' => $key]),
        ]);
    }

    private function saveKey(): void {
        $key = Http::post('key', '');
        $expire = Http::post('expire', 0);
        $old_key = Http::post('old_key', '');
        $value = Value::encode(Http::post('value', ''), Http::post('encoder', ''));

        if ($old_key !== '' && $old_key !== $key) {
            apcu_delete($old_key);
        }

        apcu_store($key, $value, $expire);

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Add/edit form.
     */
    private function form(): string {
        $key = Http::get('key', '');
        $expire = 0;

        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');

        if (isset($_GET['key']) && apcu_exists($key)) {
            $value = Helpers::mixedToString(apcu_fetch($key));
            $info = apcu_key_info($key);
            $expire = $info['ttl'];
        }

        if (isset($_POST['submit'])) {
            $this->saveKey();
        }

        $value = Value::decode($value, $encoder);

        return $this->template->render('partials/form', [
            'exp_attr' => ' min="0"',
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

        $info = apcu_cache_info();

        foreach ($info['cache_list'] as $key_data) {
            $key = $key_data['info'];

            $keys[] = [
                'key'   => base64_encode($key),
                'items' => [
                    'title'     => [
                        'title' => $key,
                        'link'  => Http::queryString([], ['view' => 'key', 'key' => $key]),
                    ],
                    'hits'      => Format::number((int) $key_data['num_hits']),
                    'last_used' => Format::time($key_data['access_time']),
                    'created'   => Format::time($key_data['creation_time']),
                    'ttl'       => $key_data['ttl'] === 0 ? 'Doesn\'t expire' : $key_data['creation_time'] + $key_data['ttl'] - time(),
                ],
            ];
        }

        return $keys;
    }

    private function mainDashboard(): string {
        $keys = $this->getAllKeys();

        if (isset($_POST['submit_import_key'])) {
            Helpers::import(
                static fn (string $key): bool => apcu_exists($key),
                static fn (string $key, string $value, int $expire): bool => apcu_store($key, $value, $expire)
            );
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/apcu', [
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($keys),
            'new_key_url' => Http::queryString([], ['form' => 'new']),
            'paginator'   => $paginator->render(),
        ]);
    }
}
