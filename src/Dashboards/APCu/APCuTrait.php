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
     * Delete key.
     *
     * @return string
     */
    private function deleteKey(): string {
        $keys = explode(',', Http::get('delete'));

        if (count($keys) > 1) {
            foreach ($keys as $key) {
                apcu_delete($key);
            }
            $message = 'Keys has been deleted.';
        } elseif ($keys[0] !== '' && apcu_delete($keys[0])) {
            $message = sprintf('Key "%s" has been deleted.', $keys[0]);
        } else {
            $message = 'No keys are selected.';
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

        $info += Helpers::getExtIniInfo('apcu');

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
                'ttl'       => $key_data['ttl'] === 0 ? -1 : (($key_data['creation_time'] + $key_data['ttl']) - time()),
                'hits'      => Format::number((int) $key_data['num_hits']),
                'last_used' => Format::time($key_data['access_time']),
                'created'   => Format::time($key_data['creation_time']),
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
            'view_url'    => Http::queryString([], ['view' => 'key', 'key' => '']),
            'paginator'   => $paginator->render(),
        ]);
    }

    /**
     * Get key and convert any value to a string.
     *
     * @param string $key
     *
     * @return string
     */
    private function getKey(string $key): string {
        $data = apcu_fetch($key);

        if (is_array($data) || is_object($data)) {
            $data = serialize($data);
        }

        return (string) $data;
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
            echo $this->getKey($key);
            exit;
        }

        if (isset($_GET['delete'])) {
            apcu_delete($key);
            Http::redirect();
        }

        $value = $this->getKey($key);

        [$value, $encode_fn, $is_formatted] = Value::format($value);

        $key_data = apcu_key_info($key);

        $ttl = $key_data['ttl'] === 0 ? -1 : (($key_data['creation_time'] + $key_data['ttl']) - time());

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
        $value = Http::post('value');

        if (isset($_GET['key']) && apcu_exists($key)) {
            $value = $this->getKey($key);
            $info = apcu_key_info($key);

            $expire = $info['ttl'];
        }

        if (isset($_POST['submit'])) {
            $this->saveKey();
        }

        $value = Value::decode($value, $encoder);

        return $this->template->render('dashboards/apcu/form', [
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }

    /**
     * Save key.
     *
     * @return void
     */
    private function saveKey(): void {
        $key = Http::post('key');
        $expire = Http::post('expire', 'int');
        $old_key = Http::post('old_key');
        $value = Value::encode(Http::post('value'), Http::post('encoder'));

        if ($old_key !== '' && $old_key !== $key) {
            apcu_delete($old_key);
        }

        apcu_store($key, $value, $expire);

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }
}
