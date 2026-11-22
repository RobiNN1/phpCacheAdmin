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
        $key = Http::get('key', '', true);

        // Read once with all metadata, it does not bump the key in the LRU or mark it as fetched.
        $item = $this->memcached->getItem($key);

        if ($item === null) {
            Http::redirect();

            return '';
        }

        $ttl = $item['exp'] === 0 ? -1 : $item['exp'];

        if (isset($_GET['export'])) {
            Helpers::export(
                [['key' => $key, 'ttl' => $ttl]],
                $key,
                fn (string $key): ?array => $this->exportValue($key)
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

        if (isset($_POST['change_ttl'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::alert('Invalid CSRF token.', 'error');
            } else {
                $this->memcached->touch($key, max(0, Http::post('ttl', 0)));
                Http::redirect([], ['view' => 'key', 'key' => $key]);
                $item = $this->memcached->getItem($key) ?? $item;
                $ttl = $item['exp'] === 0 ? -1 : $item['exp'];
            }
        }

        $value = $item['value'];
        $flags = $this->usesExtension() ? 0 : $item['flags'];
        $decompressed = MemcachedFlags::decompress($value, $flags);

        $mode = Http::get('value_mode', Value::MODE_FORMATTED);
        $mode = Value::isMode($mode) ? $mode : Value::MODE_FORMATTED;

        // Raw and hex show the bytes as stored.
        [$formatted_value, $encode_fn, $is_formatted] = Value::format($mode === Value::MODE_FORMATTED ? ($decompressed ?? $value) : $value, $mode);

        return $this->template->render('partials/view_key', [
            'key'        => $key,
            'value'      => $formatted_value,
            'ttl'        => match (true) {
                $ttl > 0 => Format::seconds($ttl),
                $ttl === -1 => -1, // the template shows "Doesn't expire"
                default => null,
            },
            'expires_at' => $ttl > 0 ? Format::time(time() + $ttl) : null,
            'size'       => Format::bytes($item['size']),
            'meta'       => $this->keyViewMeta($item, $flags),
            'ttl_form'   => true,
            'encode_fn'  => $encode_fn,
            'formatted'  => $is_formatted,
            'value_mode' => $mode,
            'edit_url'   => Http::queryString([], ['form' => 'edit', 'key' => $key]),
            'view_url'   => Http::queryString([], ['view' => 'key', 'key' => $key]),
            'export_url' => Http::queryString(['view', 'p', 'key'], ['export' => 'key']),
        ]);
    }

    /**
     * Badges shown next to the TTL and size.
     *
     * @param array<string, mixed> $item
     *
     * @return array<int, array{label: string, value: string, title?: string, color?: string}>
     */
    private function keyViewMeta(array $item, int $flags): array {
        $meta = [];

        if ($item['la'] !== null) {
            $meta[] = ['label' => 'Last used', 'value' => $item['la'] === 0 ? 'just now' : Format::seconds($item['la']).' ago'];
        }

        if ($item['fetch'] !== null) {
            $meta[] = [
                'label' => 'Fetched',
                'value' => $item['fetch'] ? 'Yes' : 'Never',
                'title' => $item['fetch'] ? 'The key was read since it was stored.' : 'Nobody has read the key since it was stored.',
                'color' => $item['fetch'] ? 'green' : 'orange',
            ];
        }

        if ($item['flags'] !== 0) {
            $notes = MemcachedFlags::describe($flags);
            $meta[] = [
                'label' => 'Flags',
                'value' => $notes !== [] ? $item['flags'].' · '.implode(', ', $notes) : (string) $item['flags'],
                'title' => $notes !== [] ? 'As the PHP memcached extension sets them.' : 'Client flags set by the client library.',
                'color' => 'purple',
            ];

            if (MemcachedFlags::isCompressed($flags) && MemcachedFlags::decompress($item['value'], $flags) === null) {
                $meta[] = ['label' => 'Compression', 'value' => 'cannot be decompressed', 'color' => 'red'];
            }
        }

        if ($item['cas'] !== null) {
            $meta[] = ['label' => 'CAS', 'value' => (string) $item['cas']];
        }

        return $meta;
    }

    /**
     * @throws MemcachedException
     */
    public function saveKey(): void {
        $key = Http::post('key', '');
        $expire = Http::post('expire', 0);
        $old_key = Http::post('old_key', '');
        $flags = $this->usesExtension() ? 0 : max(0, min(Http::post('flags', 0), 4_294_967_295));
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
            $this->memcached->delete($old_key);
        }

        $this->memcached->set($key, $value, $expire, $flags);

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Add/edit form.
     *
     * @throws MemcachedException
     */
    private function form(): string {
        $key = Http::get('key', '', true);
        $expire = Http::get('ttl', 0);
        $expire = $expire === -1 ? 0 : $expire;

        $flags = 0;

        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');

        if (isset($_GET['key']) && ($item = $this->memcached->getItem($key)) !== null) {
            $value = $item['value'];
            $flags = $this->usesExtension() ? 0 : $item['flags'];

            // The value is edited decompressed and saved as is, so the compression bits are dropped, the client reads it either way.
            if (($decompressed = MemcachedFlags::decompress($value, $flags)) !== null) {
                $value = $decompressed;
                $flags &= ~MemcachedFlags::COMPRESSION_BITS;
            }

            // Without the remaining TTL, saving an edit would make an expiring key permanent.
            if (!isset($_GET['ttl'])) {
                $expire = max(0, $item['exp']);
            }
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
            'flags'    => $this->usesExtension() ? null : $flags,
        ]);
    }
}
