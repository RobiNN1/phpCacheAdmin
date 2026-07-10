<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

class Auth {
    /**
     * Renders the login page and exits when the user is not authenticated.
     */
    public static function check(): void {
        $users = self::users();

        if ($users === []) {
            return;
        }

        // Allow a cronjob to collect metrics without a login session.
        if (self::validToken()) {
            return;
        }

        self::login($users);
    }

    public static function isEnabled(): bool {
        return self::users() !== [];
    }

    /**
     * Configured users as `username => password`, defined via the `authusers` config option.
     *
     * @return array<array-key, scalar>
     */
    private static function users(): array {
        return array_filter((array) Config::get('authusers', []), is_scalar(...));
    }

    /**
     * @param array<array-key, scalar> $users
     */
    private static function login(array $users): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_GET['logout'])) {
            unset($_SESSION['pca_auth_user']);
            Http::redirect();
        }

        $logged_user = $_SESSION['pca_auth_user'] ?? null;

        if (is_string($logged_user) && isset($users[$logged_user])) {
            return; // Already logged in.
        }

        $error = null;

        if (isset($_POST['pca_login'])) {
            $username = (string) Http::post('username', '');

            if (
                Csrf::validateToken(Http::post('csrf_token', '')) &&
                self::validate($users, $username, (string) Http::post('password', ''))
            ) {
                session_regenerate_id(true);
                $_SESSION['pca_auth_user'] = $username;
                Http::redirect();
            }

            $error = 'Incorrect username or password.';
        }

        echo (new Template())->render('login', ['error' => $error]);
        exit;
    }

    /**
     * @param array<array-key, scalar> $users
     */
    public static function validate(array $users, ?string $user, ?string $password): bool {
        if ($user === null || $password === null || !isset($users[$user])) {
            return false;
        }

        $stored = (string) $users[$user];

        // Passwords can be stored as password_hash() hashes instead of plaintext.
        if (password_get_info($stored)['algo'] !== null) {
            return password_verify($password, $stored);
        }

        return hash_equals($stored, $password);
    }

    /**
     * A token lets the metrics cronjob bypass the login while auth is enabled.
     *
     * It only grants access to the metrics collection endpoint (?ajax&metrics), nothing else.
     */
    private static function validToken(): bool {
        $token = (string) Config::get('authtoken', '');

        if ($token === '' || !isset($_GET['ajax'], $_GET['metrics'])) {
            return false;
        }

        return hash_equals($token, (string) ($_GET['token'] ?? ''));
    }
}
