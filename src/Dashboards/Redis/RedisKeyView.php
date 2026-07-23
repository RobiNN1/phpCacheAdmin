<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use JsonException;
use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Value;

trait RedisKeyView {
    /**
     * @throws Exception
     */
    private function viewKey(): string {
        $key = Http::get('key', '', true);

        if (!$this->redis->exists($key)) {
            Http::redirect();
        }

        try {
            $type = $this->redis->getKeyType($key);
        } catch (DashboardException $e) {
            return $e->getMessage();
        }

        $this->deleteSubKeyAction($type, $key);
        $this->deleteKeyAction($key);

        $ttl = $this->redis->ttl($key);

        $this->exportKeyAction($key, $ttl);

        $mode = Http::get('value_mode', Value::MODE_FORMATTED);
        $mode = Value::isMode($mode) ? $mode : Value::MODE_FORMATTED;

        $subsearch = (string) Http::get('subsearch', '');

        $value = $this->getAllKeyValues($type, $key);
        $view_data = is_array($value) ? $this->arrayViewData($key, $type, $value, $mode, $subsearch) : $this->stringViewData($value, $mode);

        return $this->template->render('partials/view_key', [
            'key'             => $key,
            'type'            => $type,
            'ttl'             => Format::seconds($ttl),
            'size'            => Format::bytes($this->redis->size($key)),
            'value_mode'      => $mode,
            'add_subkey_url'  => Http::queryString([], ['form' => 'new', 'key' => $key]),
            'edit_url'        => Http::queryString([], ['form' => 'edit', 'key' => $key]),
            'view_url'        => Http::queryString([], ['view' => 'key', 'key' => $key]),
            'export_url'      => Http::queryString(['view', 'p', 'key'], ['export' => 'key']),
            'types'           => $this->typesTplOptions(),
            'subsearch_value' => $subsearch,
            'stream_groups'   => $type === 'stream' ? $this->streamGroupsInfo($key) : [],
            'vector_set'      => $type === 'vectorset' ? $this->vectorSetPanel($key) : [],
            ...$view_data,
        ]);
    }

    /**
     * @throws Exception
     */
    private function deleteSubKeyAction(string $type, string $key): void {
        if (!isset($_POST['deletesub'])) {
            return;
        }

        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            Helpers::alert('Invalid CSRF token.', 'error');

            return;
        }

        $subkey = match ($type) {
            'set' => Http::post('member', 0),
            'list' => Http::post('index', 0),
            'zset' => Http::post('range', 0),
            'hash' => Http::post('hash_key', ''),
            'stream' => Http::post('stream_id', ''),
            'vectorset' => Http::post('element', ''),
            default => null,
        };

        $this->deleteSubKey($type, $key, $subkey);
        Http::redirect(['key', 'view', 'p', 'subsearch']);
    }

    /**
     * @throws Exception
     */
    private function deleteKeyAction(string $key): void {
        if (!isset($_POST['delete'])) {
            return;
        }

        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            Helpers::alert('Invalid CSRF token.', 'error');

            return;
        }

        $this->redis->del($key);
        Http::redirect();
    }

    /**
     * @throws Exception
     */
    private function exportKeyAction(string $key, int $ttl): void {
        if (!isset($_GET['export'])) {
            return;
        }

        Helpers::export(
            [['key' => $key, 'ttl' => $ttl]],
            $key,
            fn (string $key): string => bin2hex($this->redis->dump($key))
        );
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function arrayViewData(string $key, string $type, array $value, string $mode, string $subsearch): array {
        $pairs = [];

        foreach ($value as $item_key => $item_value) {
            $pairs[] = [$item_key, $item_value];
        }

        $total_items = count($pairs);

        if ($subsearch !== '') {
            $pairs = $this->filterSubItems($pairs, $subsearch);
        }

        $paginator = new Paginator($pairs, [['view', 'key', 'pp', 'subsearch'], ['p' => '']]);

        return [
            'value'       => $this->formatViewItems($key, $paginator->getPaginated(), $type, $mode),
            'encode_fn'   => null,
            'formatted'   => null,
            'paginator'   => $paginator->render(),
            'total_items' => $total_items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringViewData(string $value, string $mode): array {
        [$formatted_value, $encode_fn, $is_formatted] = Value::format($value, $mode);

        return [
            'value'       => $formatted_value,
            'encode_fn'   => $encode_fn,
            'formatted'   => $is_formatted,
            'paginator'   => '',
            'total_items' => 0,
        ];
    }

    /**
     * Format view array items.
     *
     * @param array<int, array{0: int|string, 1: mixed}> $value_items
     *
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    private function formatViewItems(string $key, array $value_items, string $type, string $mode = Value::MODE_FORMATTED): array {
        $items = [];

        foreach ($value_items as [$item_key, $item_value]) {
            if ($type === 'vectorset') {
                $item_value = implode(', ', $this->redis->vectorEmbedding($key, (string) $item_key));
            }

            if (is_array($item_value)) {
                try {
                    $item_value = json_encode($item_value, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $item_value = $e->getMessage();
                }
            }

            [$formatted_value, $encode_fn, $is_formatted] = Value::format($item_value, $mode);

            $items[] = [
                'key'       => $item_key,
                'value'     => $formatted_value,
                'encode_fn' => $encode_fn,
                'formatted' => $is_formatted,
                'sub_key'   => $type === 'zset' ? (string) $this->redis->zScore($key, $item_value) : $item_key,
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array{0: int|string, 1: mixed}> $pairs
     *
     * @return array<int, array{0: int|string, 1: mixed}>
     *
     * @throws JsonException
     */
    private function filterSubItems(array $pairs, string $search): array {
        $search = mb_strtolower($search);

        $filtered = array_filter($pairs, static function (array $pair) use ($search): bool {
            [$item_key, $item_value] = $pair;

            if (is_array($item_value)) {
                $item_value = json_encode($item_value, JSON_THROW_ON_ERROR);
            }

            $haystack = $item_key.' '.(is_scalar($item_value) ? (string) $item_value : '');

            return str_contains(mb_strtolower($haystack), $search);
        });

        return array_values($filtered);
    }

    /**
     * @throws Exception
     */
    public function saveKey(): void {
        $key = Http::post('key', '');
        $value = Value::converter(Http::post('value', ''), Http::post('encoder', ''), 'save');
        $old_value = Http::post('old_value', '');
        $type = Http::post('rtype', '');
        $old_key = Http::post('old_key', '');

        if ($old_key !== '' && $old_key !== $key) { // @phpstan-ignore-line
            $this->redis->rename($old_key, $key);
        }

        $this->store($type, $key, $value, $old_value, [
            'list_index' => $_POST['index'] ?? '',
            'zset_score' => (float) Http::post('score', '0'),
            'hash_key'   => Http::post('hash_key', ''),
            'stream_id'  => Http::post('stream_id', '*'),
            'element'    => Http::post('element', ''),
            'ttl'        => Http::post('expire', 0),
        ]);

        Http::redirect([], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Add/edit a form.
     *
     * @throws Exception
     */
    private function form(): string {
        $key = (string) Http::get('key', Http::post('key', ''), true);
        $type = Http::post('rtype', 'string');
        $index = $_POST['index'] ?? '';
        $score = (float) Http::post('score', '0');
        $hash_key = Http::post('hash_key', '');
        $expire = Http::post('expire', -1);
        $encoder = Http::get('encoder', 'none');
        $value = Http::post('value', '');
        $stream_id = Http::post('stream_id', '*');

        if (isset($_POST['submit'])) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                $this->saveKey();
            } else {
                Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        // edit|subkeys
        if (isset($_GET['key']) && $this->redis->exists($key)) {
            try {
                $type = $this->redis->getKeyType($key);
            } catch (DashboardException $e) {
                Helpers::alert($e->getMessage(), 'error');
                $type = 'unknown';
            }

            $expire = $this->redis->ttl($key);
        }

        if (isset($_GET['key']) && $_GET['form'] === 'edit' && $this->redis->exists($key)) {
            [$value, $index, $score, $hash_key, $stream_id] = $this->getKeyValue($type, $key);
        }

        $value = Value::converter($value, $encoder, 'view');

        return $this->template->render('dashboards/redis/form', [
            'key'       => $key,
            'value'     => $value,
            'types'     => $this->getAllTypes(),
            'type'      => $type,
            'index'     => $index,
            'score'     => $score,
            'hash_key'  => $hash_key,
            'expire'    => $expire,
            'encoders'  => Config::getEncoders(),
            'encoder'   => $encoder,
            'stream_id' => $stream_id,
            'element'   => (string) Http::get('element', Http::post('element', '')),
        ]);
    }
}
