<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use Dotenv\Dotenv;
use JsonException;

class Config {
    /**
     * Config keys that hold the options of one dashboard, so an ENV variable can address them by name.
     *
     * @var array<int, string>
     */
    private const GROUPED = ['redis', 'memcached', 'apcu', 'opcache'];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $config = null;

    private static ?string $config_path = null;

    public static function setConfigPath(string $path): void {
        self::$config_path = $path;
        self::$config = null;
    }

    /**
     * This is intended for use in tests.
     */
    public static function reset(): void {
        self::$config = null;
        self::$config_path = null;
    }

    /**
     * Load environment variables from an .env file.
     *
     * Requires vlucas/phpdotenv.
     *
     * Real environment variables (e.g., set by Docker) always take precedence over the values defined in the file.
     */
    public static function loadDotenv(?string $path = null): void {
        if (!class_exists(Dotenv::class)) {
            return;
        }

        Dotenv::createUnsafeImmutable($path ?? __DIR__.'/..', '.env')->safeLoad();
    }

    /**
     * @template Default
     *
     * @param Default $default
     *
     * @return mixed|Default
     */
    public static function get(string $key, $default = null): mixed {
        if (self::$config !== null) {
            return self::$config[$key] ?? $default;
        }

        if (self::$config_path !== null && is_file(self::$config_path)) {
            $config = (array) require self::$config_path;
        } elseif (is_file(__DIR__.'/../config.php')) {
            $config = (array) require __DIR__.'/../config.php';
        } elseif (is_file(__DIR__.'/../config.dist.php')) {
            $config = (array) require __DIR__.'/../config.dist.php';
        } else {
            exit('The configuration file is missing.');
        }

        $config = self::getEnvConfig($config);

        if ($key === 'converters' && !isset($config['converters'])) {
            $key = 'encoding';
        }

        self::$config = $config;

        return self::$config[$key] ?? $default;
    }

    /**
     * Get a single option out of one of the grouped keys, e.g., getOption('apcu', 'separator').
     *
     * @template Default
     *
     * @param Default $default
     *
     * @return mixed|Default
     */
    public static function getOption(string $group, string $option, mixed $default = null): mixed {
        $options = (array) self::get($group, []);

        return $options[$option] ?? $default;
    }

    /**
     * Get config from ENV.
     *
     * All keys from the config file are supported ENV variables, they just must start with PCA_ prefix.
     *
     * E.g.:
     * PCA_TIMEFORMAT
     * PCA_REDIS_1_HOST = 1 is server id
     * PCA_MEMCACHED_0_HOST ...
     *
     * @param array<string, mixed> $config The default config that will be merged with ENV.
     *
     * @return array<string, mixed>
     */
    private static function getEnvConfig(array $config): array {
        foreach (getenv() as $var => $value) {
            if (str_starts_with($var, 'PCA_')) {
                self::envVarToArray($config, $var, $value);
            }
        }

        return $config;
    }

    private static function startsWithAny(string $name): bool {
        foreach (self::GROUPED as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert an ENV variable to an array.
     *
     * It allows the app to use ENV variables and config.php together.
     *
     * @param array<string, mixed> $array
     */
    private static function envVarToArray(array &$array, string $var, string $value): void {
        $lower_var = strtolower(substr($var, 4));

        if (json_validate($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                //
            }
        }

        // Options grouped per dashboard: PCA_REDIS_1_HOST $config['redis'][1]['host'], PCA_APCU_SEPARATOR $config['apcu']['separator'].
        // A name with no underscore after the group (PCA_REDISCLIENT) stays at the top level, there is nothing to nest it in.
        if (self::startsWithAny($lower_var)) {
            $keys = explode('_', $lower_var);
            $final_key = array_pop($keys);

            $current = &$array;

            foreach ($keys as $key) {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }

                $current = &$current[$key];
            }

            $current[$final_key] = $value;
        } else {
            // All other variables: PCA_AUTH_PASSWORD $config['auth_password']
            $array[$lower_var] = $value;
        }
    }

    /**
     * Get encoders.
     *
     * Used in forms.
     *
     * @return array<string, string>
     */
    public static function getEncoders(): array {
        $encoders = self::get('converters', []);

        if ($encoders === []) {
            return [];
        }

        $names = [...array_keys($encoders), 'none'];

        return array_combine($names, $names);
    }
}
