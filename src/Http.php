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

class Http {
    private static bool $stop_redirect = false;

    /**
     * Prevent redirecting.
     *
     * @return void
     *
     */
    public static function stopRedirect(): void {
        self::$stop_redirect = true;
    }

    /**
     * Query string manipulation.
     *
     * @param array<int|string, string>     $filter
     * @param array<int|string, int|string> $additional
     *
     * @return string
     */
    public static function queryString(array $filter = [], array $additional = []): string {
        $keep = ['type', 'server'];
        $filter = array_flip(array_merge($keep, $filter));
        $query = [];

        if ($url = parse_url($_SERVER['REQUEST_URI'])) {
            parse_str($url['query'] ?? '', $query);

            $query = array_intersect_key($query, $filter);
        }

        $query += $additional;

        return ($query !== [] ? '?' : '').http_build_query($query);
    }

    /**
     * Get query parameter.
     *
     * @param string          $key
     * @param string          $type
     * @param string|int|null $default
     *
     * @return string|int
     */
    public static function get(string $key, string $type = 'string', $default = null) {
        $filter = FILTER_SANITIZE_FULL_SPECIAL_CHARS;

        if ($type === 'int') {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        $value = isset($_GET[$key]) ? filter_var($_GET[$key], $filter) : $default;

        return $type === 'int' ? (int) $value : (string) $value;
    }

    /**
     * Get post value.
     *
     * @param string          $key
     * @param string          $type
     * @param string|int|null $default
     *
     * @return string|int
     */
    public static function post(string $key, string $type = 'string', $default = null) {
        $filter = FILTER_UNSAFE_RAW;

        if ($type === 'int') {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        $value = isset($_POST[$key]) ? filter_var($_POST[$key], $filter) : $default;

        return $type === 'int' ? (int) $value : (string) $value;
    }

    /**
     * Redirect.
     *
     * @param array<int|string, string>     $filter
     * @param array<int|string, int|string> $additional
     *
     * @return void
     */
    public static function redirect(array $filter = [], array $additional = []): void {
        if (self::$stop_redirect === false) {
            $location = self::queryString($filter, $additional);

            if (!headers_sent()) {
                header('Location: '.$location);
            }

            echo '<script data-cfasync="false">window.location.replace("'.$location.'");</script>';

            exit();
        }
    }
}
