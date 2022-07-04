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
        $query += $additional;

        return ($query !== [] ? '?' : '').http_build_query($query);
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
     * @param array $filter
     * @param array $additional
     *
     * @return void
     */
    public static function redirect(array $filter = [], array $additional = []): void {
        $location = self::queryString($filter, $additional);

        if (!headers_sent()) {
            header('Location: '.$location, true);
        } else {
            echo '<script data-cfasync="false">window.location.replace("'.$location.'");</script>';
        }
    }
}
