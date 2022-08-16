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

use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait APCuTrait {
    /**
     * Delete key.
     *
     * @return string
     */
    private function deleteKey(): string {
        $keys = explode(',', Http::get('delete'));

        if (count($keys) === 1) {
            apcu_delete($keys[0]);
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } else {
            foreach ($keys as $key) {
                apcu_delete($key);
            }
            $message = 'Keys has been deleted.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Show more info.
     *
     * @param array<string, mixed> $info
     *
     * @return string
     */
    private function moreInfo(array $info): string {
        unset($info['cache_list']);

        foreach (apcu_sma_info() as $mem_name => $mem_value) {
            if (!is_array($mem_value)) {
                $info['memory'][$mem_name] = $mem_value;
            }
        }

        foreach (ini_get_all('apcu') as $ini_name => $ini_value) {
            $ini_name = str_replace('apc.', '', $ini_name);
            $info['ini_config'][$ini_name] = $ini_value['local_value'];
        }

        return $this->template->render('partials/info_table', [
            'panel_title' => 'APCu Info',
            'array'       => Helpers::convertBoolToString($info),
        ]);
    }

    /**
     * Get all keys with data.
     *
     * @param array<string, mixed> $info
     *
     * @return array<int, array<string, string|int>>
     */
    private function getAllKeys(array $info): array {
        static $keys = [];

        foreach ($info['cache_list'] as $key_data) {
            $keys[] = [
                'key'       => $key_data['info'],
                'ttl'       => $key_data['ttl'] === 0 ? -1 : $key_data['ttl'],
                'hits'      => Helpers::formatNumber((int) $key_data['num_hits']),
                'last_used' => Helpers::formatTime($key_data['access_time']),
                'created'   => Helpers::formatTime($key_data['creation_time']),
            ];
        }

        return $keys;
    }

    /**
     * Main dashboard content.
     *
     * @param array<string, mixed> $info
     *
     * @return string
     */
    private function mainDashboard(array $info): string {
        $keys = $this->getAllKeys($info);

        if (isset($_POST['submit_import_key'])) {
            $this->import();
        }

        $paginator = new Paginator($this->template, $keys);

        return $this->template->render('dashboards/apcu/apcu', [
            'keys'        => $paginator->getPaginated(),
            'all_keys'    => count($keys),
            'new_key_url' => Http::queryString([], ['form' => 'new']),
            'edit_url'    => Http::queryString([], ['form' => 'edit', 'key' => '']),
            'view_url'    => Http::queryString([], ['view' => 'key', 'key' => '']),
            'paginator'   => $paginator->render(),
        ]);
    }

    /**
     * View key value.
     *
     * @return string
     */
    private function viewKey(): string {
        $key = Http::get('key');

        if (!apcu_exists($key)) {
            Http::redirect();
        }

        if (isset($_GET['export'])) {
            header('Content-disposition: attachment; filename='.$key.'.txt');
            header('Content-Type: text/plain');
            echo apcu_fetch($key);
            exit;
        }

        if (isset($_GET['delete'])) {
            apcu_delete($key);
            Http::redirect(['db']);
        }

        $value = apcu_fetch($key);

        [$value, $encode_fn, $is_formatted] = Helpers::decodeAndFormatValue($value);

        $info = apcu_key_info($key);

        $ttl = $info['ttl'] === 0 ? -1 : $info['ttl'];

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $value,
            'type'       => 'string',
            'ttl'        => !empty($ttl) ? Helpers::formatSeconds($ttl) : null,
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
     * @return void
     */
    private function import(): void {
        if ($_FILES['import']['type'] === 'text/plain') {
            $key_name = Http::post('key_name');

            if (!apcu_exists($key_name)) {
                $value = file_get_contents($_FILES['import']['tmp_name']);

                apcu_store($key_name, $value, Http::post('expire', 'int'));

                Http::redirect();
            }
        }
    }

    /**
     * Add/edit form.
     *
     * @return string
     */
    private function form(): string {
        $key = Http::get('key');
        $expire = 0;

        $encoder = Http::get('encoder', 'string', 'none');
        $value = Helpers::decodeValue(Http::post('value'), $encoder);

        if (isset($_GET['key']) && apcu_exists($key)) {
            $value = apcu_fetch($key);
            $info = apcu_key_info($key);

            $expire = $info['ttl'];
        }

        if (isset($_POST['submit'])) {
            $key = Http::post('key');
            $expire = Http::post('expire', 'int');
            $old_key = Http::post('old_key');
            $encoder = Http::post('encoder');
            $value = Helpers::encodeValue($value, $encoder);

            if (!empty($old_key) && $old_key !== $key) {
                apcu_delete($old_key);
            }

            apcu_store($key, $value, $expire);

            Http::redirect([], ['view' => 'key', 'key' => $key]);
        }

        return $this->template->render('dashboards/apcu/form', [
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Helpers::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }
}
