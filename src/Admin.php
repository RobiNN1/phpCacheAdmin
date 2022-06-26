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

class Admin {
    /**
     * @const string phpCacheAdmin version.
     */
    public const VERSION = '1.0.0';

    /**
     * Get config.
     *
     * @param ?string $key
     *
     * @return mixed
     */
    public static function getConfig(?string $key = null) {
        $config = (array) require __DIR__.'/../config.php';

        return $key !== null ? $config[$key] : $config;
    }

    /**
     * Check if OpCache is installed.
     *
     * @return bool
     */
    public static function checkOpCache(): bool {
        return extension_loaded('Zend OPcache');
    }

    /**
     * Check if Redis is installed.
     *
     * @return bool
     */
    public static function checkRedis(): bool {
        return extension_loaded('redis');
    }

    /**
     * Check if Memcached is installed.
     *
     * @return bool
     */
    public static function checkMemcached(): bool {
        return extension_loaded('memcache') || extension_loaded('memcached');
    }

    /**
     * Get current dashboard.
     *
     * @return string
     */
    public static function currentDashboard(): string {
        $current = filter_input(INPUT_GET, 'type');

        $dashboards = [];

        $dashboards[] = 'server';

        if (self::checkRedis()) {
            $dashboards[] = 'redis';
        }

        if (self::checkMemcached()) {
            $dashboards[] = 'memcached';
        }

        if (self::checkOpCache()) {
            $dashboards[] = 'opcache';
        }

        return !empty($current) && in_array($current, $dashboards, true) ? $current : 'server';
    }

    /**
     * Get svg icon from file.
     *
     * @param string $icon
     * @param int    $size
     *
     * @return ?string
     */
    public static function svg(string $icon, int $size = 16): ?string {
        $file = __DIR__.'/../assets/icons/'.$icon.'.svg';

        if (is_file($file)) {
            $content = trim(file_get_contents($file));
            $attributes = 'width="'.$size.'" height="'.$size.'" fill="currentColor" viewBox="0 0 16 16"';

            return preg_replace('~<svg([^<>]*)>~', '<svg xmlns="http://www.w3.org/2000/svg" '.$attributes.'>', $content);
        }

        return null;
    }

    /**
     * Format size.
     *
     * @param int $bytes
     *
     * @return string
     */
    public static function formatSize(int $bytes): string {
        if ($bytes > 1048576) {
            return sprintf('%.2fMB', $bytes / 1048576);
        }

        if ($bytes > 1024) {
            return sprintf('%.2fkB', $bytes / 1024);
        }

        return sprintf('%dbytes', $bytes);
    }

    /**
     * Format seconds.
     *
     * @param int  $time
     * @param bool $ago
     *
     * @return string
     */
    public static function formatSeconds(int $time, bool $ago = false): string {
        $seconds_in_minute = 60;
        $seconds_in_hour = 60 * $seconds_in_minute;
        $seconds_in_day = 24 * $seconds_in_hour;

        $days = floor($time / $seconds_in_day);

        $hour_seconds = $time % $seconds_in_day;
        $hours = floor($hour_seconds / $seconds_in_hour);

        $minute_seconds = $hour_seconds % $seconds_in_hour;
        $minutes = floor($minute_seconds / $seconds_in_minute);

        //$remainingSeconds = $minute_seconds % $seconds_in_minute;
        //$seconds = ceil($remainingSeconds);

        $time_parts = [];
        $sections = [
            'day'    => (int) $days,
            'hour'   => (int) $hours,
            'minute' => (int) $minutes,
            //'second' => (int) $seconds,
        ];

        foreach ($sections as $name => $value) {
            if ($value > 0) {
                $time_parts[] = $value.' '.$name.($value === 1 ? '' : 's');
            }
        }

        return implode(' ', $time_parts).($ago ? ' ago' : '');
    }

    /**
     * Return JSON data for ajax.
     *
     * @param array $info
     *
     * @return string
     */
    public static function returnJson(array $info): string {
        header('Content-Type: application/json');
        try {
            return json_encode($info, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            return '{"error": "'.$e->getMessage().'"}';
        }
    }

    /**
     * Paginate array.
     *
     * @param array $keys
     * @param bool  $sort
     * @param int   $default_per_page
     *
     * @return array
     */
    public static function paginate(array &$keys, bool $sort = true, int $default_per_page = 15): array {
        $per_page = (int) self::get('pp', 'int');
        $per_page = !empty($per_page) ? $per_page : $default_per_page;

        if ($sort) {
            usort($keys, static fn ($a, $b) => strcmp((string) $a['key'], (string) $b['key']));
        }

        $keys = array_chunk($keys, $per_page, true);
        array_unshift($keys, '');
        unset($keys[0]);

        $pages = [];

        for ($i = 1, $max = count($keys); $i <= $max; $i++) {
            $pages[] = $i;
        }

        $page = (int) self::get('p', 'int');
        $page = !empty($page) ? $page : 1;

        $first_page = !empty($keys[1]) ? $keys[1] : [];
        $keys = !empty($keys[$page]) ? $keys[$page] : $first_page;

        return [$pages, $page, $per_page];
    }

    /**
     * Query string manipulation.
     *
     * @param array $filter
     * @param array $additional
     *
     * @return string
     */
    public static function queryString(array $filter = [], array $additional = []): string {
        $keep = ['type', 'server'];
        $filter = array_flip(array_merge($keep, $filter));
        $url = parse_url($_SERVER['REQUEST_URI']);

        if (empty($url['query'])) {
            return $url['path'];
        }

        parse_str($url['query'], $query);

        $query = array_intersect_key($query, $filter);
        //$query = array_diff_key($query, $filter); // remove query strings
        $query += $additional;

        return ($query ? '?' : '').http_build_query($query);
    }

    /**
     * Get query parameter.
     *
     * @param string $key
     * @param string $type
     *
     * @return string|int
     */
    public static function get(string $key, string $type = 'string') {
        $filter = FILTER_SANITIZE_FULL_SPECIAL_CHARS;

        if ($type === 'int') {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        if (filter_has_var(INPUT_GET, $key)) {
            $value = filter_input(INPUT_GET, $key, $filter);
        } else {
            $value = isset($_GET[$key]) ? filter_var($_GET[$key], $filter) : null;
        }

        return $type === 'int' ? (int) $value : (string) $value;
    }

    /**
     * Get post value.
     *
     * @param string $key
     * @param string $type
     *
     * @return string|int
     */
    public static function post(string $key, string $type = 'string') {
        $filter = FILTER_SANITIZE_FULL_SPECIAL_CHARS;

        if ($type === 'int') {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        if (filter_has_var(INPUT_POST, $key)) {
            $value = filter_input(INPUT_POST, $key, $filter);
        } else {
            $value = isset($_POST[$key]) ? filter_var($_POST[$key], $filter) : null;
        }

        return $type === 'int' ? (int) $value : (string) $value;
    }

    /**
     * Redirect.
     *
     * @param string $location
     *
     * @return void
     */
    public static function redirect(string $location): void {
        if (!headers_sent()) {
            header('Location: '.$location, true);
        } else {
            echo '<script data-cfasync="false">window.location.replace("'.$location.'");</script>';
        }
    }

    /**
     * Show status badge.
     *
     * @param Template $template
     * @param bool     $enabled
     * @param ?string  $text
     * @param ?array   $badge_text
     *
     * @return string
     */
    public static function enabledDisabledBadge(Template $template, bool $enabled = true, ?string $text = null, ?array $badge_text = null): string {
        $badge_text = $badge_text ?: ['Enabled', 'Disabled'];

        return $template->render('components/badge', [
            'text' => $enabled ? $badge_text[0].$text : $badge_text[1],
            'bg'   => $enabled ? 'bg-green-600' : 'bg-red-600',
            'pill' => true,
        ]);
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
        $template->addTplGlobal('alerts', $template->render('components/alert', [
            'message'     => $message,
            'alert_color' => $color,
        ]));
    }

    /**
     * Convert bool to string in array.
     * Used for more info page.
     *
     * @param array $array
     *
     * @return array
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
}
