<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use JsonException;

class Auth {
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT = 300;

    private const ATTEMPT_WINDOW = 900;

    private const THROTTLE_ENTRIES = 1000;

    /**
     * The placeholder shipped in config.dist.php and .env.example. It is public knowledge, so it can never be a valid token.
     */
    private const TOKEN_PLACEHOLDER = 'your-secret-token';

    /**
     * A password hash to verify against when the user does not exist, so a wrong name costs the same as a wrong password.
     * Without it, the response time tells the two apart.
     */
    private const DUMMY_HASH = '$2y$12$q2ryraE6O/s5PROCABktIuO2TE5Dwk8SlYI3EY6xy6kha1uv0Od4K';

    /**
     * Renders the login page and exits when the user is not authenticated.
     */
    public static function check(): void {
        Http::securityHeaders();

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
     * The name the current session is logged in with, so the header can show whose session it is.
     */
    public static function user(): ?string {
        $user = $_SESSION['pca_auth_user'] ?? null;

        return is_string($user) && isset(self::users()[$user]) ? $user : null;
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
        Http::startSession();

        if (isset($_POST['pca_logout']) && Csrf::validateToken(Http::post('csrf_token', ''))) {
            unset($_SESSION['pca_auth_user']);
            session_regenerate_id(true);
            Http::redirect();
        }

        $logged_user = $_SESSION['pca_auth_user'] ?? null;

        if (is_string($logged_user) && isset($users[$logged_user])) {
            return; // Already logged in.
        }

        $error = null;

        if (isset($_POST['pca_login'])) {
            $wait = self::lockedFor();

            if ($wait > 0) {
                $error = sprintf('Too many failed attempts. Try again in %d seconds.', $wait);
            } else {
                $username = (string) Http::post('username', '');

                if (
                    Csrf::validateToken(Http::post('csrf_token', '')) &&
                    self::validate($users, $username, (string) Http::post('password', ''))
                ) {
                    self::clearAttempts();
                    session_regenerate_id(true);
                    $_SESSION['pca_auth_user'] = $username;
                    Http::redirect();
                }

                self::registerFailure();
                $error = 'Incorrect username or password.';
            }
        }

        echo (new Template())->render('login', ['error' => $error]);
        exit;
    }

    /**
     * Failed attempts are counted per address in one file.
     */
    private static function throttleFile(): string {
        return Config::get('tmpdir', __DIR__.'/../tmp').'/auth_throttle.json';
    }

    private static function throttleKey(): string {
        // Hashed so the file does not become a list of addresses.
        return hash('xxh128', ($_SERVER['REMOTE_ADDR'] ?? 'unknown').Config::get('hash', 'pca'));
    }

    /**
     * @return array<string, array{n: int, t: int}>
     */
    private static function decodeAttempts(string $json): array {
        if ($json === '') {
            return [];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? array_filter($data, static fn (mixed $entry): bool => is_array($entry) && isset($entry['n'], $entry['t'])) : [];
    }

    /**
     * @return array<string, array{n: int, t: int}>
     */
    private static function readAttempts(): array {
        $file = self::throttleFile();

        if (!is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }

            $attempts = self::decodeAttempts((string) stream_get_contents($handle));
            flock($handle, LOCK_UN);

            return $attempts;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read, change, and write the counters back while holding one exclusive lock.
     *
     * @param callable(array<string, array{n: int, t: int}>): array<string, array{n: int, t: int}> $change
     */
    private static function updateAttempts(callable $change): void {
        $file = self::throttleFile();

        if (!Helpers::makeDir(dirname($file))) {
            return;
        }

        $handle = @fopen($file, 'cb+');

        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $attempts = self::trimAttempts($change(self::decodeAttempts((string) stream_get_contents($handle))));

            try {
                $json = json_encode($attempts, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, array{n: int, t: int}> $attempts
     *
     * @return array<string, array{n: int, t: int}>
     */
    private static function trimAttempts(array $attempts): array {
        // Drop what has expired first, only trim by age when that is still not enough.
        $attempts = array_filter($attempts, static fn (array $entry): bool => time() - (int) $entry['t'] < self::ATTEMPT_WINDOW);

        if (count($attempts) > self::THROTTLE_ENTRIES) {
            uasort($attempts, static fn (array $a, array $b): int => $b['t'] <=> $a['t']);
            $attempts = array_slice($attempts, 0, self::THROTTLE_ENTRIES, true);
        }

        return $attempts;
    }

    /**
     * Seconds left before the address may try again, 0 when it may try now.
     */
    private static function lockedFor(): int {
        $entry = self::readAttempts()[self::throttleKey()] ?? null;

        if ($entry === null || (int) $entry['n'] < self::MAX_ATTEMPTS) {
            return 0;
        }

        $elapsed = time() - (int) $entry['t'];

        return $elapsed < self::LOCKOUT ? self::LOCKOUT - $elapsed : 0;
    }

    private static function registerFailure(): void {
        $key = self::throttleKey();

        self::updateAttempts(static function (array $attempts) use ($key): array {
            $entry = $attempts[$key] ?? null;

            $expired = $entry === null || time() - (int) $entry['t'] > self::ATTEMPT_WINDOW;
            $attempts[$key] = ['n' => $expired ? 1 : (int) $entry['n'] + 1, 't' => time()];

            return $attempts;
        });
    }

    private static function clearAttempts(): void {
        $key = self::throttleKey();

        self::updateAttempts(static function (array $attempts) use ($key): array {
            unset($attempts[$key]);

            return $attempts;
        });
    }

    /**
     * @param array<array-key, scalar> $users
     */
    public static function validate(array $users, ?string $user, ?string $password): bool {
        if ($user === null || $password === null || !isset($users[$user])) {
            password_verify((string) $password, self::DUMMY_HASH);

            return false;
        }

        $stored = (string) $users[$user];

        // Passwords can be stored as password_hash() hashes instead of plaintext.
        if (password_get_info($stored)['algo'] !== null) {
            return password_verify($password, $stored);
        }

        password_verify($password, self::DUMMY_HASH); // Keeps the plaintext path as slow as the hashed one.

        return hash_equals($stored, $password);
    }

    /**
     * A token lets the metrics cronjob bypass the login while auth is enabled.
     */
    private static function validToken(): bool {
        $token = trim((string) Config::get('authtoken', ''));

        if ($token === '' || $token === self::TOKEN_PLACEHOLDER || !isset($_GET['ajax'], $_GET['metrics'])) {
            return false;
        }

        $request_token = $_SERVER['HTTP_X_PCA_TOKEN'] ?? $_GET['token'] ?? '';

        if (!is_string($request_token) || !hash_equals($token, $request_token)) {
            return false;
        }

        $allowed = ['ajax', 'metrics', 'dashboard', 'server', 'db', 'token'];
        $_GET = array_intersect_key($_GET, array_fill_keys($allowed, true));

        return true;
    }
}
