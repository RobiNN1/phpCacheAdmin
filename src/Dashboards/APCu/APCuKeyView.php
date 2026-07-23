<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use APCUIterator;
use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Value;

trait APCuKeyView {
    private function getKeySize(string $key): int {
        $iterator = new APCUIterator('/^'.preg_quote($key, '/').'$/', APC_ITER_MEM_SIZE, 0, APC_LIST_ACTIVE);

        return $iterator->valid() ? $iterator->current()['mem_size'] : 0;
    }

    private function viewKey(): string {
        $key = Http::get('key', '', true);

        if (apcu_exists($key) === false) {
            Http::redirect();
        }

        $value = Helpers::mixedToString(apcu_fetch($key));
        $key_data = apcu_key_info($key);
        $ttl = $key_data['ttl'] === 0 ? -1 : $key_data['creation_time'] + $key_data['ttl'] - time();

        if (isset($_GET['export'])) {
            Helpers::export(
                [['key' => $key, 'ttl' => $ttl]],
                $key,
                static fn (string $key): string => base64_encode(serialize(apcu_fetch($key)))
            );
        }

        if (isset($_POST['delete'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::alert('Invalid CSRF token.', 'error');
            } else {
                apcu_delete($key);
                Http::redirect();
            }
        }

        $mode = Http::get('value_mode', Value::MODE_FORMATTED);
        $mode = Value::isMode($mode) ? $mode : Value::MODE_FORMATTED;

        [$formatted_value, $encode_fn, $is_formatted] = Value::format($value, $mode);

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $formatted_value,
            'ttl'        => Format::seconds($ttl),
            'size'       => Format::bytes($this->getKeySize($key)),
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'value_mode' => $mode,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'view_url'   => Http::queryString(['ttl'], ['view' => 'key', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
        ]);
    }

    public function saveKey(): void {
        $key = Http::post('key', '');
        $expire = Http::post('expire', 0);
        $old_key = Http::post('old_key', '');
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
            apcu_delete($old_key);
        }

        apcu_store($key, $value, $expire);

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Add/edit form.
     */
    private function form(): string {
        $key = Http::get('key', '', true);
        $expire = 0;

        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');

        if (isset($_GET['key']) && apcu_exists($key)) {
            $value = Helpers::mixedToString(apcu_fetch($key));
            $info = apcu_key_info($key);
            $expire = $info['ttl'];
        }

        if (isset($_POST['submit'])) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                $this->saveKey();
            } else {
                Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        $value = Value::converter($value, $encoder, 'view');

        return $this->template->render('partials/form', [
            'exp_attr' => 'min="0"',
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }
}
