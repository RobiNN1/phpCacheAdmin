<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use JsonException;

class Helpers {
    /**
     * @param string $icon Icon name from `assets/icons/`, custom path or svg code.
     */
    public static function svg(string $icon, ?int $size = 16, ?string $class = null): string {
        static $cache = [];

        $cache_key = $icon.'|'.$size.'|'.$class;

        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $file = is_file($icon) ? $icon : __DIR__.'/../assets/icons/'.$icon.'.svg';
        $content = is_file($file) ? trim(file_get_contents($file)) : $icon;

        preg_match('~<svg([^<>]*)>~', $content, $attributes);

        $size_attr = $size !== null ? ' width="'.$size.'" height="'.$size.'"' : '';
        $class_attr = $class !== null ? ' class="'.$class.'"' : '';
        $svg = preg_replace('~<svg([^<>]*)>~', '<svg'.($attributes[1] ?? '').$size_attr.$class_attr.'>', $content);
        $svg = preg_replace('/\s+/', ' ', $svg);

        return $cache[$cache_key] = str_replace("\n", '', $svg);
    }

    public static function alert(string $message, ?string $color = null): string {
        $template = Template::get();

        $alert = $template->render('components/alert', [
            'message'     => $message,
            'alert_color' => $color, // success/error
        ]);

        $template->addGlobal('alerts', $alert);

        return $alert;
    }

    /**
     * @param array<int|string, mixed> $array
     *
     * @return array<int|string, mixed>
     */
    public static function convertTypesToString(array $array): array {
        foreach ($array as $name => $value) {
            if (is_array($value)) {
                $array[$name] = self::convertTypesToString($value);
            } elseif (is_bool($value)) {
                $array[$name] = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $array[$name] = 'null';
            } elseif ($value === '') {
                $array[$name] = 'empty';
            } else {
                $array[$name] = $value;
            }
        }

        return $array;
    }

    public static function mixedToString(mixed $data): string {
        if (is_array($data) || is_object($data)) {
            $data = serialize($data);
        }

        return (string) $data;
    }

    /**
     * Get configuration info for a given extension.
     *
     * @return array<string, array<string, int|string|bool>>
     */
    public static function getExtIniInfo(string $extension): array {
        static $cache = [];

        if (isset($cache[$extension])) {
            return $cache[$extension];
        }

        if (!extension_loaded($extension)) {
            return $cache[$extension] = [];
        }

        $ini_config = array_map(static function (array $ini_value) {
            return $ini_value['local_value'];
        }, ini_get_all($extension));

        return $cache[$extension] = ['ini_config' => $ini_config];
    }

    public static function deleteKey(callable $delete_key): string {
        try {
            $keys = json_decode(Http::post('delete', ''), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $keys = [];
        }

        if (is_string($keys) && $delete_key(base64_decode($keys))) {
            return self::alert(sprintf('Key "%s" has been deleted.', base64_decode($keys)), 'success');
        }

        if (is_array($keys) && count($keys)) {
            foreach ($keys as $key) {
                $delete_key(base64_decode($key));
            }

            return self::alert('Keys have been deleted.', 'success');
        }

        return self::alert('No keys are selected.');
    }

    public static function import(callable $exists, callable $store): void {
        if (!isset($_FILES['import']) || $_FILES['import']['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        $file = (string) file_get_contents($_FILES['import']['tmp_name']);

        try {
            $json = json_decode($file, true, 512, JSON_THROW_ON_ERROR);

            foreach ((array) $json as $data) {
                if (is_array($data) && isset($data['key'], $data['value'], $data['ttl']) && !$exists($data['key'])) {
                    $store($data['key'], $data['value'], (int) $data['ttl']);
                }
            }
        } catch (JsonException) {
            //
        }

        Http::redirect(['view']);
    }

    /**
     * @param array<int, mixed> $keys
     */
    public static function export(array $keys, string $filename, callable $value, bool $tests = false): ?string {
        $json = [];

        foreach ($keys as $key) {
            $val = $value($key['key']);
            if ($val === null) {
                continue;
            }

            $ttl = $key['info']['ttl'] ?? $key['ttl'] ?? 0;
            $ttl = is_int($ttl) ? $ttl : 0;

            $json[] = [
                'key'   => $key['key'],
                'ttl'   => $ttl === -1 ? 0 : $ttl,
                'value' => $val,
            ];
        }

        try {
            $output = json_encode($json, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $output = $e->getMessage();
        }

        if ($tests) {
            return $output;
        }

        $safe_filename = preg_replace('/[\x00-\x1f"\\\\]+/', '', $filename);
        header('Content-disposition: attachment; filename="'.$safe_filename.'.json"');
        header('Content-Type: application/json');
        echo $output;
        exit;
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function getServerTitle(array $server): string {
        $name = $server['name'] ?? 'Server';

        if (!empty($server['sentinels']) && is_array($server['sentinels'])) {
            return $name.' - '.($server['sentinelmaster'] ?? 'mymaster');
        }

        $host = isset($server['host']) ? ' - '.$server['host'] : '';
        $port = isset($server['port']) ? ':'.$server['port'] : '';

        return $name.$host.$port;
    }

    public static function cronjobUrl(string $dashboard, int $server_id): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ||
            ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $host = ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = (parse_url(($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        $url = ($https ? 'https' : 'http').'://'.$host.$path.'?dashboard='.$dashboard.'&server='.$server_id.'&ajax&metrics';

        $token = (string) Config::get('authtoken', '');

        if ($token !== '' && Auth::isEnabled()) {
            $url .= '&token='.rawurlencode($token);
        }

        return $url;
    }

    /**
     * @param array<int|string, mixed> $panels
     */
    public static function panels(array $panels): string {
        if (isset($panels['error']) && is_string($panels['error'])) {
            return $panels['error'];
        }

        $html = '';

        foreach ($panels as $panel) {
            if (is_array($panel) && $panel !== []) {
                $html .= Template::get()->render('partials/panel', [
                    'panel_title' => $panel['title'] ?? null,
                    'array'       => $panel['data'] ?? [],
                ]);
            }
        }

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $servers
     */
    public static function serverSelector(array $servers, int $selected): string {
        if (count($servers) === 1) {
            return '';
        }

        $options = array_map(self::getServerTitle(...), $servers);

        return Template::get()->render('components/select', [
            'id'            => 'server_select',
            'options'       => $options,
            'selected'      => $selected,
            'wrapper_class' => false,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $keys
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sortKeys(array $keys): array {
        $dir = Http::get('sortdir', 'none');
        $column = Http::get('sortcol', 'none');

        Template::get()->addGlobal('sortdir', $dir);
        Template::get()->addGlobal('sortcol', $column);

        if (strtolower($dir) === 'none' || strtolower($column) === 'none') {
            return $keys;
        }

        usort($keys, static function (array $a, array $b) use ($dir, $column): int {
            $a_val = (string) $a['info'][$column];
            $b_val = (string) $b['info'][$column];
            $comparison = strnatcmp($a_val, $b_val);

            return strtolower($dir) === 'desc' ? -$comparison : $comparison;
        });

        return $keys;
    }

    /**
     * Build a folder tree for the tree view from a flat list of keys.
     *
     * Each item must contain the full key name under 'key'; everything else ('info', 'items', ...) is copied to the leaf node as is.
     *
     * @param array<int, array<string, mixed>> $keys
     * @param callable|null                    $leaf_name Optional formatter for the displayed leaf name.
     *
     * @return array<int|string, mixed>
     */
    public static function keysTree(array $keys, string $separator, ?callable $leaf_name = null): array {
        $tree = [];

        foreach ($keys as $key_item) {
            $key = (string) $key_item['key'];
            $parts = explode($separator, $key);
            $last = count($parts) - 1;

            /** @var array<int|string, mixed> $current */
            $current = &$tree;
            $path = '';

            foreach ($parts as $i => $part) {
                $path = $path === '' ? $part : $path.$separator.$part;

                if ($i === $last) {
                    $current[] = [
                            'type' => 'key',
                            'name' => $leaf_name !== null ? $leaf_name($part) : $part,
                        ] + $key_item;
                } else {
                    if (!isset($current[$part])) {
                        $current[$part] = [
                            'type'     => 'folder',
                            'name'     => $part,
                            'path'     => $path,
                            'children' => [],
                            'expanded' => false,
                        ];
                    }

                    $current = &$current[$part]['children'];
                }
            }

            unset($current);
        }

        self::countChildren($tree);

        return $tree;
    }

    /**
     * @param array<int|string, mixed> &$tree
     */
    public static function countChildren(array &$tree): int {
        $count = 0;

        foreach ($tree as &$item) {
            if (isset($item['type']) && $item['type'] === 'folder') {
                $item['count'] = self::countChildren($item['children']);
                $count += $item['count'];
            } else {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    public static function formatFields(array $fields, array $item): array {
        $formatted = [];

        // key_name => [label, formatting (number, bytes, seconds, time)]
        foreach ($fields as $key => [$label, $type]) {
            $value = $item[$key] ?? 0;
            $formatted[$label] = $type !== '' ? Format::{$type}($value) : $value;
        }

        return $formatted;
    }

    public static function snakeCase(string $string): string {
        $string = preg_replace('/[^a-z0-9]+/i', ' ', $string);

        return strtolower(str_replace(' ', '_', trim($string)));
    }

    /**
     * @param array<int|string, mixed> $panel_data
     */
    public static function getPanelsJson(array $panel_data): string {
        header('Content-Type: application/json');

        $api_data = [];

        if (isset($panel_data['error'])) {
            try {
                return json_encode($panel_data, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
            }
        }

        foreach ($panel_data as $panel) {
            if (empty($panel['title'])) {
                continue;
            }

            $section_key = self::snakeCase($panel['title']);
            $api_data[$section_key] = [];

            foreach ($panel['data'] as $key => $value) {
                if (is_int($key) && is_array($value) && $value !== []) {
                    $item_key = self::snakeCase($value[0]);
                    $api_data[$section_key][$item_key] = array_slice($value, 1);
                } else {
                    $item_key = self::snakeCase((string) $key);
                    $api_data[$section_key][$item_key] = $value;
                }
            }
        }

        try {
            return json_encode($api_data, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
        }

        return '';
    }

    public static function utilizationStatus(float $percentage): string {
        return match (true) {
            $percentage > 80 => 'critical',
            $percentage > 50 => 'warning',
            default => 'healthy',
        };
    }

    public static function hitRateStatus(float $percentage): string {
        return match (true) {
            $percentage >= 80 => 'healthy',
            $percentage >= 50 => 'warning',
            default => 'critical',
        };
    }
}
