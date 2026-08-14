<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use Random\RandomException;
use RuntimeException;

class Csrf {
    public static function generateToken(): string {
        $token = Http::session('csrf_token', '');

        if ($token === '') {
            Http::startSession();

            try {
                $token = bin2hex(random_bytes(32));
            } catch (RandomException $e) {
                throw new RuntimeException('Could not generate secure random bytes.', 0, $e);
            }

            $_SESSION['csrf_token'] = $token;
        }

        return (string) $token;
    }

    public static function validateToken(?string $token): bool {
        $session_token = Http::session('csrf_token', '');

        if ($session_token === '' || in_array($token, [null, '', '0'], true)) {
            return false;
        }

        return hash_equals((string) $session_token, $token);
    }
}
