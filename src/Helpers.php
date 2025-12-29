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
        $file = is_file($icon) ? $icon : __DIR__.'/../assets/icons/'.$icon.'.svg';
        $content = is_file($file) ? trim(file_get_contents($file)) : $icon;

        preg_match('~<svg([^<>]*)>~', $content, $attributes);

        $size_attr = $size !== null ? ' width="'.$size.'" height="'.$size.'"' : '';
        $class_attr = $class !== null ? ' class="'.$class.'"' : '';
        $svg = preg_replace('~<svg([^<>]*)>~', '<svg'.($attributes[1] ?? '').$size_attr.$class_attr.'>', $content);
        $svg = preg_replace('/\s+/', ' ', $svg);

        return str_replace("\n", '', $svg);
    }

    public static function alert(Template $template, string $message, ?string $color = null): string {
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
        static $info = [];

        if (extension_loaded($extension)) {
            foreach (ini_get_all($extension) as $ini_name => $ini_value) {
                $info['ini_config'][$ini_name] = $ini_value['local_value'];
            }
        }

        return $info;
    }

    public static function deleteKey(Template $template, callable $delete_key, bool $base64 = false): string {
        try {
            $keys = json_decode(Http::post('delete', ''), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $keys = [];
        }

        $b64_decode = static fn ($key): string => $base64 ? base64_decode($key) : $key;

        if (is_string($keys) && $delete_key($b64_decode($keys))) {
            return self::alert($template, sprintf('Key "%s" has been deleted.', $b64_decode($keys)), 'success');
        }

        if (is_array($keys) && count($keys)) {
            foreach ($keys as $key) {
                $delete_key($b64_decode($key));
            }

            return self::alert($template, 'Keys has been deleted.', 'success');
        }

        return self::alert($template, 'No keys are selected.');
    }

    public static function import(callable $exists, callable $store): void {
        if ($_FILES['import']['type'] === 'application/json') {
            $file = file_get_contents($_FILES['import']['tmp_name']);

            try {
                $json = json_decode($file, true, 512, JSON_THROW_ON_ERROR);

                foreach ($json as $data) {
                    if (!$exists($data['key'])) {
                        $store($data['key'], $data['value'], (int) $data['ttl']);
                    }
                }
            } catch (JsonException) {
                //
            }

            Http::redirect(['view']);
        }
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

            $ttl = isset($key['info']['ttl']) && is_int($key['info']['ttl']) ? $key['info']['ttl'] : 0;

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

        header('Content-disposition: attachment; filename='.$filename.'.json');
        header('Content-Type: application/json');
        echo $output;
        exit;
    }

    /**
     * @param array<string, int|string> $server
     */
    public static function getServerTitle(array $server): string {
        $name = $server['name'] ?? 'Server';
        $host = isset($server['host']) ? ' - '.$server['host'] : '';
        $port = isset($server['port']) ? ':'.$server['port'] : '';

        return $name.$host.$port;
    }

    /**
     * @param array<int, array<string, int|string>> $servers
     */
    public static function serverSelector(Template $template, array $servers, int $selected): string {
        if (count($servers) === 1) {
            return '';
        }

        $options = array_map(self::getServerTitle(...), $servers);

        return $template->render('components/select', [
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
    public static function sortKeys(Template $template, array $keys): array {
        $dir = Http::get('sortdir', 'none');
        $column = Http::get('sortcol', 'none');

        $template->addGlobal('sortdir', $dir);
        $template->addGlobal('sortcol', $column);

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
}
