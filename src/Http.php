<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use Random\RandomException;
use RuntimeException;

class Http {
    private static bool $stop_redirect = false;

    private static ?string $nonce = null;

    public static function stopRedirect(): void {
        self::$stop_redirect = true;
    }

    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ||
            (int) ($_SERVER['SERVER_PORT'] ?? '0') === 443;
    }

    /**
     * Start the session with cookie flags that PHP does not set on its own.
     */
    public static function startSession(): void {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true, // The session cookie is of no use to any script on the page.
            'samesite' => 'Lax',
            'secure'   => self::isHttps(),
        ]);

        // Refuse a session id the browser was given by someone else.
        ini_set('session.use_strict_mode', '1');

        session_start();
    }

    /**
     * A per-request value that marks the inline scripts of the page as the ones it wrote itself.
     */
    public static function nonce(): string {
        if (self::$nonce === null) {
            try {
                self::$nonce = bin2hex(random_bytes(16));
            } catch (RandomException $e) {
                throw new RuntimeException('Could not generate secure random bytes.', 0, $e);
            }
        }

        return self::$nonce;
    }

    /**
     * Sent before any output. The CSP is what keeps a value stored in the cache from becoming a script.
     */
    public static function securityHeaders(): void {
        if (headers_sent() || !Config::get('securityheaders', true)) {
            return;
        }

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-".self::nonce()."'",
            "style-src 'self' 'unsafe-inline'", // Progress bars and dashboard colors are inline styles.
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'self'", // 'self' rather than 'none', the dashboard can be embedded in another page.
        ];

        header('Content-Security-Policy: '.implode('; ', $csp));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        // Keeps the metrics token out of the referrer of any link that leaves the dashboard.
        header('Referrer-Policy: same-origin');
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
     * Set $raw to true for values that are data rather than markup (e.g., cache keys, which may legitimately contain <, >, etc.)
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

            echo '<script nonce="'.self::nonce().'">window.location.replace("'.$location.'");</script>';

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
        self::startSession();

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
