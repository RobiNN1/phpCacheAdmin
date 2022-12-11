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

    public static function stopRedirect(): void {
        self::$stop_redirect = true;
    }

    /**
     * Query string manipulation.
     *
     * @param array<int|string, string>     $filter     Parameters to be preserved.
     * @param array<int|string, int|string> $additional Additional parameters with their new value.
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
     * @template Type
     *
     * @param Type $default
     *
     * @return Type
     */
    public static function get(string $key, $default = null) {
        $filter = FILTER_SANITIZE_FULL_SPECIAL_CHARS;

        if (is_int($default)) {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        $value = isset($_GET[$key]) ? filter_var($_GET[$key], $filter) : $default;

        return is_int($default) ? (int) $value : $value;
    }

    /**
     * Get post value.
     *
     * @template Type
     *
     * @param Type $default
     *
     * @return Type
     */
    public static function post(string $key, $default = null) {
        $filter = FILTER_UNSAFE_RAW;

        if (is_int($default)) {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        $value = isset($_POST[$key]) ? filter_var($_POST[$key], $filter) : $default;

        return is_int($default) ? (int) $value : $value;
    }

    /**
     * @param array<int|string, string>     $filter     Parameters to be preserved.
     * @param array<int|string, int|string> $additional Additional parameters with their new value.
     */
    public static function redirect(array $filter = [], array $additional = []): void {
        if (self::$stop_redirect === false) {
            $location = self::queryString($filter, $additional);

            if (!headers_sent()) {
                header('Location: '.$location);
            }

            echo '<script data-cfasync="false">window.location.replace("'.$location.'");</script>';

            exit;
        }
    }
}
