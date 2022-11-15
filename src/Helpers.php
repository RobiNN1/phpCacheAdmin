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

namespace RobiNN\Pca;

use JsonException;

class Helpers {
    /**
     * @param array<string, mixed> $data
     */
    public static function returnJson(array $data): string {
        header('Content-Type: application/json');

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return '{"error": "'.$e->getMessage().'"}';
        }
    }

    /**
     * Get svg icon from file.
     *
     * @param string $icon Icon name from `assets/icons/` or custom path.
     */
    public static function svg(string $icon, ?int $size = 16, ?string $class = null): string {
        $file = is_file($icon) ? $icon : __DIR__.'/../assets/icons/'.$icon.'.svg';

        if (is_file($file)) {
            $content = trim(file_get_contents($file));
        } else {
            $content = $icon;
        }

        preg_match('~<svg([^<>]*)>~', $content, $attributes);

        $size_attr = $size ? ' width="'.$size.'" height="'.$size.'"' : '';
        $class_attr = $class ? ' class="'.$class.'"' : '';

        return preg_replace('~<svg([^<>]*)>~', '<svg'.$attributes[1].$size_attr.$class_attr.'>', $content);
    }

    public static function alert(Template $template, string $message, ?string $color = null): void {
        $template->addGlobal('alerts', $template->render('components/alert', [
            'message'     => $message,
            'alert_color' => $color,
        ]));
    }

    /**
     * Convert bool to string in an array.
     *
     * @param array<int|string, mixed> $array
     *
     * @return array<int|string, mixed>
     */
    public static function convertBoolToString(array $array): array {
        foreach ($array as $name => $value) {
            if (is_array($value)) {
                $array[$name] = self::convertBoolToString($value);
            } elseif (is_bool($value)) {
                $array[$name] = $value ? 'true' : 'false';
            } else {
                $array[$name] = $value;
            }
        }

        return $array;
    }

    public static function str_starts_with(string $haystack, string $needle): bool {
        if (!function_exists('str_starts_with')) {
            return strncmp($haystack, $needle, strlen($needle)) === 0;
        }

        return str_starts_with($haystack, $needle);
    }

    public static function str_ends_with(string $haystack, string $needle): bool {
        if (!function_exists('str_ends_with')) {
            $needleLength = strlen($needle);

            return $needleLength <= strlen($haystack) && 0 === substr_compare($haystack, $needle, -$needleLength);
        }

        return str_ends_with($haystack, $needle);
    }

    /**
     * Get configuration info for a given extension.
     *
     * @return array<string, array<string, int|string|bool>>
     */
    public static function getExtIniInfo(string $extension): array {
        static $info = [];

        foreach (ini_get_all($extension) as $ini_name => $ini_value) {
            $info['ini_config'][$ini_name] = $ini_value['local_value'];
        }

        return $info;
    }

    /**
     * Delete key or selected keys.
     */
    public static function deleteKey(Template $template, callable $function, bool $base64 = false): string {
        try {
            $keys = json_decode(Http::post('delete'), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $keys = [];
        }

        if (is_array($keys) && count($keys)) {
            foreach ($keys as $key) {
                $function($base64 ? base64_decode($key) : $key);
            }
            $message = 'Keys has been deleted.';
        } elseif (is_string($keys) && $function($base64 ? base64_decode($keys) : $keys)) {
            $message = sprintf('Key "%s" has been deleted.', $base64 ? base64_decode($keys) : $keys);
        } else {
            $message = 'No keys are selected.';
        }

        return $template->render('components/alert', ['message' => $message]);
    }

    /**
     * Convert mixed data to string.
     *
     * @param mixed $data
     */
    public static function mixedToString($data): string {
        if (is_array($data) || is_object($data)) {
            $data = serialize($data);
        }

        return (string) $data;
    }
}
