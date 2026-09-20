<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use Random\RandomException;
use Throwable;

class AuthSetup {
    public const MIN_PASSWORD = 8;

    public const MAX_PASSWORD = 72;

    private const USERNAME = '~^[\w.@-]{1,64}$~';

    private const AUTHUSERS = '~^([ \t]*)([\'"])authusers\2\s*=>\s*\[[^\[\]]*],[ \t]*$~m';

    public static function isAllowed(): bool {
        return self::blockedBy() === null;
    }

    private static function blockedBy(): ?string {
        if (Auth::isEnabled()) {
            return 'Authentication is already enabled.';
        }

        if (getenv('PCA_AUTHUSERS') !== false) {
            return 'The users are defined in the environment (PCA_AUTHUSERS), set them there.';
        }

        if (!Config::get('authwarning', true)) {
            return 'The notice about the missing authentication is turned off ("authwarning").';
        }

        return null;
    }

    public static function handle(): string {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        $blocked = self::blockedBy();

        if ($blocked !== null) {
            return self::json(['error' => $blocked]);
        }

        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            return self::json(['error' => 'Invalid CSRF token.']);
        }

        $user = trim((string) Http::post('authuser', ''));
        $password = (string) Http::post('authpassword', '');
        $error = self::validate($user, $password, (string) Http::post('authpassword2', ''));

        if ($error !== null) {
            return self::json(['error' => $error]);
        }

        return self::save($user, $password);
    }

    private static function validate(string $user, string $password, string $repeat): ?string {
        if (preg_match(self::USERNAME, $user) !== 1) {
            return 'The username can only be letters, digits and . _ - @, up to 64 characters.';
        }

        if (strlen($password) < self::MIN_PASSWORD) {
            return 'The password has to be at least '.self::MIN_PASSWORD.' characters long.';
        }

        if (strlen($password) > self::MAX_PASSWORD) {
            return 'The password can be at most '.self::MAX_PASSWORD.' bytes long, the rest would not be hashed.';
        }

        if ($password !== $repeat) {
            return 'The passwords do not match.';
        }

        return null;
    }

    private static function save(string $user, string $password): string {
        $file = Config::file();
        $contents = self::contents($file);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($contents === null) {
            return self::json(['error' => 'The configuration file could not be read: '.$file, 'snippet' => self::snippet($user, $hash)]);
        }

        $new_contents = self::replace($contents, $user, $hash);

        if ($new_contents === null) {
            return self::json([
                'error'   => 'The "authusers" option was not found in '.basename($file).', add the user by hand.',
                'snippet' => self::snippet($user, $hash),
            ]);
        }

        if (!self::write($file, $new_contents, $user, $password)) {
            return self::json([
                'error'   => 'Could not write to '.$file.'. Give the web server write access to it, or add the user by hand.',
                'snippet' => self::snippet($user, $hash),
            ]);
        }

        self::logIn($user);

        return self::json(['message' => 'Saved in '.basename($file).'. You are logged in as "'.$user.'".']);
    }

    /**
     * The current configuration, or the dist file when there is nothing to copy from yet.
     */
    private static function contents(string $file): ?string {
        $source = is_file($file) ? $file : __DIR__.'/../config.dist.php';

        if (!is_file($source) || !is_readable($source)) {
            return null;
        }

        $contents = @file_get_contents($source);

        return $contents !== false ? $contents : null;
    }

    private static function replace(string $contents, string $user, string $hash): ?string {
        if (preg_match_all(self::AUTHUSERS, $contents) !== 1) {
            return null;
        }

        // A callback, a replacement string would read the $ in a hash as a reference.
        $replaced = preg_replace_callback(self::AUTHUSERS, static function (array $matches) use ($user, $hash): string {
            $indent = $matches[1];

            return $indent."'authusers' => [".PHP_EOL.
                $indent.'    '.var_export($user, true).' => '.var_export($hash, true).','.PHP_EOL.
                $indent.'],';
        }, $contents, 1);

        return $replaced !== null && $replaced !== $contents ? $replaced : null;
    }

    private static function write(string $file, string $contents, string $user, string $password): bool {
        $tmp = self::tmpFile($file);

        if ($tmp === null) {
            return false;
        }

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false || !self::verify($tmp, $user, $password)) {
            @unlink($tmp);

            return false;
        }

        // Credentials do not belong in a world-readable file.
        @chmod($tmp, is_file($file) ? (fileperms($file) & 0777) : 0640);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        clearstatcache(true, $file);

        return true;
    }

    /**
     * Next to the config file so the rename over it is atomic, with a .php suffix so it can never be served as text.
     */
    private static function tmpFile(string $file): ?string {
        if (!is_dir(dirname($file))) {
            return null;
        }

        try {
            return $file.'.'.bin2hex(random_bytes(8)).'.php';
        } catch (RandomException) {
            return null;
        }
    }

    private static function verify(string $file, string $user, string $password): bool {
        try {
            $config = include $file;
        } catch (Throwable) {
            return false;
        }

        if (!is_array($config) || !isset($config['authusers']) || !is_array($config['authusers'])) {
            return false;
        }

        return Auth::validate(array_filter($config['authusers'], is_scalar(...)), $user, $password);
    }

    /**
     * The password was set right here, so the session continues as that user.
     */
    private static function logIn(string $user): void {
        Http::startSession();
        unset($_SESSION['csrf_token']);
        session_regenerate_id(true);
        $_SESSION['pca_auth_user'] = $user;
    }

    private static function snippet(string $user, string $hash): string {
        return "'authusers' => [".PHP_EOL.'    '.var_export($user, true).' => '.var_export($hash, true).','.PHP_EOL.'],';
    }

    /**
     * @param array<string, string> $data
     */
    private static function json(array $data): string {
        return Helpers::ajaxJson($data);
    }
}
