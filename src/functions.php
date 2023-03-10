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

// https://github.com/symfony/polyfill-php80/blob/main/Php80.php

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return 0 === strncmp($haystack, $needle, strlen($needle));
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ('' === $needle || $needle === $haystack) {
            return true;
        }

        if ('' === $haystack) {
            return false;
        }

        $needleLength = strlen($needle);

        return $needleLength <= strlen($haystack) && 0 === substr_compare($haystack, $needle, -$needleLength);
    }
}

function autoload(string $path): void {
    if (is_file($path.'twig.phar')) {
        require_once 'phar://'.$path.'twig.phar/vendor/autoload.php';
    }

    if (!extension_loaded('redis') && is_file($path.'predis.phar')) {
        require_once 'phar://'.$path.'predis.phar/vendor/autoload.php';
    }

    spl_autoload_register(static function (string $class_name) use ($path): void {
        $class_name = str_replace("RobiNN\\Pca\\", '', $class_name);
        $filename = str_replace("\\", DIRECTORY_SEPARATOR, $class_name);

        $fullpath = $path.'src/'.$filename.'.php';

        if (is_file($fullpath)) {
            require_once $fullpath;
        }
    });
}

if (!extension_loaded('xdebug')) {
    set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $type = static function (int $errno): string {
            $constants = get_defined_constants(true);
            if (array_key_exists('Core', $constants)) {
                foreach ($constants['Core'] as $constant => $value) {
                    if ($value === $errno && strpos($constant, 'E_') === 0) {
                        return $constant;
                    }
                }
            }

            return 'E_UNKNOWN';
        };

        $errstr = htmlspecialchars($errstr);

        echo '<div class="text-red-500">';
        echo $type($errno).': '.$errstr.' in '.$errfile.' on line '.$errline;
        echo '</div>';

        return true;
    });
}
