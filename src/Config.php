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

class Config {
    /**
     * Get config.
     *
     * @param string $key
     *
     * @return array<int|string, mixed>|bool|int|string|null
     */
    public static function get(string $key) {
        if (is_file(__DIR__.'/../config.php')) {
            $config = (array) require __DIR__.'/../config.php';
        } elseif (is_file(__DIR__.'/../config.dist.php')) {
            $config = (array) require __DIR__.'/../config.dist.php';
        } else {
            exit('The configuration file is missing.');
        }

        $config = self::getEnvConfig($config);

        return $config[$key] ?? null;
    }

    /**
     * Get config from ENV.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function getEnvConfig(array $config): array {
        // All keys must start with PCA_ prefix.
        // E.g.
        // PCA_TIMEFORMAT
        // PCA_REDIS_1_HOST = 1 is server id
        // PCA_MEMCACHED_0_HOST ...
        $vars = preg_grep('/^PCA_/', array_keys(getenv()));

        if (count($vars)) {
            foreach ($vars as $var) {
                self::envVarToArray($config, $var, getenv($var));
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
     * @param string               $array_key
     * @param string               $value
     *
     * @return void
     */
    private static function envVarToArray(array &$array, string $array_key, string $value): void {
        $array_key = str_replace('PCA_', '', $array_key);
        $keys = explode('_', $array_key);
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

        $array[array_shift($keys)] = $value;
    }

    /**
     * Get encoders.
     *
     * @return array<int, string>
     */
    public static function getEncoders(): array {
        $encoders = self::get('encoding');

        if (count($encoders) === 0) {
            return [];
        }

        $array = array_keys($encoders);
        $array[] = 'none';

        return array_combine($array, $array);
    }
}
