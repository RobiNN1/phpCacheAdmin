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
     * @param ?string $key
     *
     * @return mixed
     */
    public static function get(?string $key = null) {
        if (is_file(__DIR__.'/../config.php')) {
            $config = (array) require __DIR__.'/../config.php';
        } elseif (is_file(__DIR__.'/../config.dist.php')) {
            $config = (array) require __DIR__.'/../config.dist.php';
        } else {
            exit('The configuration file is missing.');
        }

        self::getEnvConfig($config);

        return $config[$key] ?? null;
    }

    /**
     * Get config from ENV.
     *
     * @param array<string, mixed> $config
     *
     * @return void
     */
    private static function getEnvConfig(array &$config): void {
        // All keys must start with PCA_ prefix.
        // E.g.
        // PCA_TIMEFORMAT
        // PCA_REDIS_1_HOST = 1 is server id
        // PCA_MEMCACHED_0_HOST ...
        $vars = preg_grep('/^PCA_/', array_keys(getenv()));

        if (!empty($vars)) {
            foreach ($vars as $var) {
                Helpers::envVarToArray($config, $var, getenv($var));
            }
        }
    }
}
