<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

class Http {
    private static bool $stop_redirect = false;

    public static function stopRedirect(): void {
        self::$stop_redirect = true;
    }

    /**
     * Generate a query string based on the provided filter and additional parameters.
     *
     * @param array<int|string, string>     $preserve   Parameters to be preserved.
     * @param array<int|string, int|string> $additional Additional parameters with their new value.
     */
    public static function queryString(array $preserve = [], array $additional = []): string {
        static $cached_uri = null;
        static $cached_query = [];

        $uri = ($_SERVER['REQUEST_URI'] ?? '');

        if ($uri !== $cached_uri) {
            $cached_uri = $uri;
            $cached_query = [];

            if ($uri !== '') {
                $query_part = parse_url($uri, PHP_URL_QUERY);

                if (is_string($query_part) && $query_part !== '') {
                    parse_str($query_part, $cached_query);
                }
            }
        }

        $keep = ['dashboard', 'server', 'db', 's', 'sortdir', 'sortcol'];
        $query = array_intersect_key($cached_query, array_fill_keys(array_merge($keep, $preserve), true));
        $query += $additional;

        return $query !== [] ? '?'.http_build_query($query) : '';
    }

    /**
     * Get query parameter.
     * Set $raw to true for values that are data rather than markup (e.g. cache keys, which may legitimately contain <, >, etc.)
     *
     * @template Type
     *
     * @param Type $default
     *
     * @return Type
     */
    public static function get(string $key, mixed $default = null, bool $raw = false): mixed {
        if (!isset($_GET[$key]) || is_array($_GET[$key])) {
            return $default;
        }

        if (is_int($default)) {
            return (int) filter_var($_GET[$key], FILTER_SANITIZE_NUMBER_INT);
        }

        return filter_var($_GET[$key], $raw ? FILTER_UNSAFE_RAW : FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }

    /**
     * Get POST value.
     *
     * @template Type
     *
     * @param Type $default
     *
     * @return Type
     */
    public static function post(string $key, mixed $default = null): mixed {
        if (!isset($_POST[$key]) || is_array($_POST[$key])) {
            return $default;
        }

        $filter = is_int($default) ? FILTER_SANITIZE_NUMBER_INT : FILTER_UNSAFE_RAW;
        $value = filter_var($_POST[$key], $filter);

        return is_int($default) ? (int) $value : $value;
    }

    /**
     * @param array<int|string, string>     $preserve   Parameters to be preserved.
     * @param array<int|string, int|string> $additional Additional parameters with their new value.
     */
    public static function redirect(array $preserve = [], array $additional = []): void {
        if (self::$stop_redirect === false) {
            $location = self::queryString($preserve, $additional);
            $location = $location !== '' ? $location : '?';

            if (!headers_sent()) {
                header('Location: '.$location);
            }

            echo '<script>window.location.replace("'.$location.'");</script>';

            exit;
        }
    }

    /**
     * Get session value.
     *
     * @template Type
     *
     * @param Type $default
     *
     * @return Type
     */
    public static function session(string $key, mixed $default = null): mixed {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION[$key])) {
            return $default;
        }

        if (!is_scalar($_SESSION[$key])) {
            return $_SESSION[$key];
        }

        $filter = is_int($default) ? FILTER_SANITIZE_NUMBER_INT : FILTER_UNSAFE_RAW;
        $value = filter_var($_SESSION[$key], $filter);

        return is_int($default) ? (int) $value : $value;
    }
}
