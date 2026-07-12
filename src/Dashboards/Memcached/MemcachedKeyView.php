<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Value;

trait MemcachedKeyView {
    /**
     * @throws MemcachedException
     */
    private function viewKey(): string {
        $key = Http::get('key', '');

        if (!$this->memcached->exists($key)) {
            Http::redirect();
        }

        $info = $this->memcached->getKeyMeta($key);
        $ttl = $info['exp'] ?? null;
        $ttl = $ttl === 0 ? -1 : $ttl;

        if (isset($_GET['export'])) {
            Helpers::export(
                [['key' => $key, 'ttl' => $ttl]],
                $key,
                fn (string $key): string => base64_encode($this->memcached->get($key))
            );
        }

        if (isset($_POST['delete'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::alert('Invalid CSRF token.', 'error');
            } else {
                $this->memcached->delete($key);
                Http::redirect();
            }
        }

        $value = $this->memcached->get($key);

        [$formatted_value, $encode_fn, $is_formatted] = Value::format($value);

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $formatted_value,
            'ttl'        => $ttl ? Format::seconds($ttl) : null,
            'size'       => isset($info['size']) ? Format::bytes($info['size']) : null,
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'edit_url'   => Http::queryString(['ttl'], ['form' => 'edit', 'key' => $key]),
            'view_url'   => Http::queryString(['ttl'], ['view' => 'key', 'key' => $key]),
            'export_url' => Http::queryString(['ttl', 'view', 'p', 'key'], ['export' => 'key']),
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

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
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
            $value = $this->memcached->get($key);
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
            'exp_attr' => 'min="0" max="2592000"',
            'key'      => $key,
            'value'    => $value,
            'expire'   => $expire,
            'encoders' => Config::getEncoders(),
            'encoder'  => $encoder,
        ]);
    }
}
