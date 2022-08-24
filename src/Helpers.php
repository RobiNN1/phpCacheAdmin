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

use Exception;

class Helpers {
    /**
     * Return JSON data for ajax.
     *
     * @param array<string, mixed> $data
     *
     * @return string
     */
    public static function returnJson(array $data): string {
        header('Content-Type: application/json');

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            return '{"error": "'.$e->getMessage().'"}';
        }
    }

    /**
     * Get svg icon from file.
     *
     * @param string  $icon Icon name from `assets/icons/` or custom path.
     * @param ?int    $size
     * @param ?string $class
     *
     * @return ?string
     */
    public static function svg(string $icon, ?int $size = 16, ?string $class = null): ?string {
        $file = is_file($icon) ? $icon : __DIR__.'/../assets/icons/'.$icon.'.svg';

        if (!is_file($file)) {
            return null;
        }

        $content = trim(file_get_contents($file));
        preg_match('~<svg([^<>]*)>~', $content, $attributes);

        $size_attr = $size ? ' width="'.$size.'" height="'.$size.'"' : '';
        $class_attr = $class ? ' class="'.$class.'"' : '';

        return preg_replace('~<svg([^<>]*)>~', '<svg'.$attributes[1].$size_attr.$class_attr.'>', $content);
    }

    /**
     * Show alert.
     *
     * @param Template $template
     * @param string   $message
     * @param ?string  $color
     *
     * @return void
     */
    public static function alert(Template $template, string $message, ?string $color = null): void {
        $template->addGlobal('alerts', $template->render('components/alert', [
            'message'     => $message,
            'alert_color' => $color,
        ]));
    }

    /**
     * Show enabled/disabled badge.
     *
     * @param Template            $template
     * @param bool                $enabled
     * @param ?string             $text
     * @param ?array<int, string> $badge_text
     *
     * @return string
     */
    public static function enabledDisabledBadge(Template $template, bool $enabled = true, ?string $text = null, ?array $badge_text = null): string {
        $badge_text = $badge_text ?? ['Enabled', 'Disabled'];

        return $template->render('components/badge', [
            'text' => $enabled ? $badge_text[0].$text : $badge_text[1],
            'bg'   => $enabled ? 'bg-green-600' : 'bg-red-600',
            'pill' => true,
        ]);
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

    /**
     * Checks if a string starts with a given substring.
     *
     * From Symfony polyfills.
     *
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    public static function str_starts_with(string $haystack, string $needle): bool {
        if (!function_exists('str_starts_with')) {
            return strncmp($haystack, $needle, strlen($needle)) === 0;
        }

        return str_starts_with($haystack, $needle);
    }

    /**
     * Get configuration info for a given extension.
     *
     * @param string $extension
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
}
