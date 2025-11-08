<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

// https://github.com/symfony/polyfill-php83/blob/main/Php83.php

if (!function_exists('json_validate')) {
    function json_validate(string $json, int $depth = 512, int $flags = 0): bool {
        $JSON_MAX_DEPTH = 0x7FFFFFFF; // see https://www.php.net/manual/en/function.json-decode.php

        if (0 !== $flags && defined('JSON_INVALID_UTF8_IGNORE') && JSON_INVALID_UTF8_IGNORE !== $flags) {
            throw new ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
        }

        if ($depth <= 0) {
            throw new ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
        }

        if ($depth > $JSON_MAX_DEPTH) {
            throw new ValueError(sprintf('json_validate(): Argument #2 ($depth) must be less than %d', $JSON_MAX_DEPTH));
        }

        try {
            json_decode($json, null, $depth, JSON_THROW_ON_ERROR | $flags);
        } catch (JsonException) {
            return false;
        }

        return true;
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

if (PHP_SAPI !== 'cli' && !extension_loaded('xdebug')) {
    set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if ((error_reporting() & $errno) === 0) {
            return false;
        }

        $type = static function (int $errno): string {
            $constants = get_defined_constants(true);
            if (array_key_exists('Core', $constants)) {
                foreach ($constants['Core'] as $constant => $value) {
                    if ($value === $errno && str_starts_with($constant, 'E_')) {
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
