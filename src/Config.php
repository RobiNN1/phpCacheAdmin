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
     * @param mixed $default
     *
     * @return array<int|string, mixed>|bool|int|string|null
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
        $encoders = self::get('encoding');

        if (($encoders === null ? 0 : count($encoders)) === 0) {
            return [];
        }

        $array = array_keys($encoders);
        $array[] = 'none';

        return array_combine($array, $array);
    }
}
