<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use JsonException;

class Config {
    /**
     * @template Default
     *
     * @param Default $default
     *
     * @return mixed|Default
     */
    public static function get(string $key, $default = null) {
        if (is_file(__DIR__.'/../config.php')) {
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

        return $config[$key] ?? $default;
    }

    /**
     * Get config from ENV.
     *
     * All keys from the config file are supported ENV variables, they just must start with PCA_ prefix.
     * Since keys with underscores are converted to an array, use a dash to create a space (-).
     *
     * E.g.
     * PCA_TIME-FORMAT
     * PCA_REDIS_1_HOST = 1 is server id
     * PCA_MEMCACHED_0_HOST ...
     *
     * @param array<string, mixed> $config The default config that will be merged with ENV.
     *
     * @return array<string, mixed>
     */
    private static function getEnvConfig(array $config): array {
        $vars = preg_grep('/^PCA_/', array_keys(getenv()));

        if ($vars !== false && count($vars)) {
            foreach ($vars as $var) {
                self::envVarToArray($config, $var, (string) getenv($var));
            }
        }

        return $config;
    }

    /**
     * Convert ENV variable to an array.
     *
     * It allows app to use ENV variables and config.php together.
     *
     * @param array<string, mixed> $array
     */
    private static function envVarToArray(array &$array, string $var, string $value): void {
        $var = str_replace('PCA_', '', $var);
        $keys = explode('_', $var);
        $keys = array_map('strtolower', $keys);

        foreach ($keys as $i => $key) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        if (json_validate($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                //
            }
        }

        $array[array_shift($keys)] = $value;
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

        $encoders_array = array_keys($encoders);
        $encoders_array[] = 'none';

        static $array = [];

        foreach ($encoders_array as $encoder) {
            $array[$encoder] = $encoder;
        }

        return $array;
    }
}
