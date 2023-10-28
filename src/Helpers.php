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

    /**
     * @param mixed $data
     */
    public static function mixedToString($data): string {
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

    /**
     * Return key(s) as JSON.
     *
     * This method exists mainly as a helper for tests.
     *
     * @param array<int, string>|string|null $keys
     *
     * @throws JsonException
     */
    private static function keysJson($keys = null, bool $base64 = false): string {
        if ($keys === null) {
            return Http::post('delete', '');
        }

        if ($base64) {
            $keys = is_array($keys) ? array_map(static fn (string $key): string => base64_encode($key), $keys) : base64_encode($keys);
        }

        return json_encode($keys, JSON_THROW_ON_ERROR);
    }

    /**
     * Delete key or selected keys.
     *
     * @param array<int, string>|string|null $keys_for_json
     */
    public static function deleteKey(Template $template, callable $delete_key, bool $base64 = false, $keys_for_json = null): string {
        try {
            $keys = json_decode(self::keysJson($keys_for_json, $base64), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $keys = [];
        }

        if (is_array($keys) && count($keys)) {
            foreach ($keys as $key) {
                $delete_key($base64 ? base64_decode($key) : $key);
            }

            return self::alert($template, 'Keys has been deleted.', 'success');
        }

        if (is_string($keys) && $delete_key($base64 ? base64_decode($keys) : $keys)) {
            $message = sprintf('Key "%s" has been deleted.', $base64 ? base64_decode($keys) : $keys);

            return self::alert($template, $message, 'success');
        }

        return self::alert($template, 'No keys are selected.');
    }

    public static function import(callable $exists, callable $store, string $type = 'text/plain'): void {
        if ($_FILES['import']['type'] === $type) {
            $key_name = Http::post('key_name', '');

            if (!$exists($key_name)) {
                $value = file_get_contents($_FILES['import']['tmp_name']);

                $store($key_name, $value, Http::post('expire', 0));

                Http::redirect(['db']);
            }
        }
    }

    public static function export(string $key, string $value, string $ext = 'txt', string $type = 'text/plain'): void {
        header('Content-disposition: attachment; filename='.$key.'.'.$ext);
        header('Content-Type: '.$type);

        echo $value;

        exit;
    }

    /**
     * @param array<string, int|string> $server
     */
    public static function getServerTitle(array $server): string {
        $name = $server['name'] ?? '';
        $host = isset($server['host']) ? ' - '.$server['host'] : '';
        $port = isset($server['port']) ? ':'.$server['port'] : '';

        return $name.$host.$port;
    }

    /**
     * @param array<int, array<string, int|string>> $servers
     */
    public static function serverSelector(Template $template, array $servers, int $selected): string {
        $options = [];

        foreach ($servers as $id => $server) {
            $options[$id] = self::getServerTitle($server);
        }

        return $template->render('components/select', [
            'id'       => 'server_select',
            'options'  => $options,
            'selected' => $selected,
            'class'    => 'mb-3',
        ]);
    }
}
