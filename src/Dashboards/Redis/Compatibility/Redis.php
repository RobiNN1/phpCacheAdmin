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

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use RedisException;
use RobiNN\Pca\Dashboards\DashboardException;

class Redis extends \Redis implements CompatibilityInterface {
    /**
     * @param array<string, int|string> $server
     *
     * @throws DashboardException
     */
    public function connection(array $server): self {
        $server['port'] ??= 6379;

        try {
            if (isset($server['path'])) {
                $this->connect($server['path']);
            } else {
                $this->connect($server['host'], (int) $server['port'], 3);
            }

            if (isset($server['password'])) {
                if (isset($server['username'])) {
                    $credentials = [$server['username'], $server['password']];
                } else {
                    $credentials = $server['password'];
                }

                $this->auth($credentials);
            }

            $this->select($server['database'] ?? 0);
        } catch (RedisException $e) {
            $connection = $server['path'] ?? $server['host'].':'.$server['port'];
            throw new DashboardException($e->getMessage().' ['.$connection.']');
        }

        return $this;
    }

    /**
     * @var array<int, string>
     */
    private array $data_types = [
        \Redis::REDIS_NOT_FOUND => 'other',
        \Redis::REDIS_STRING    => 'string',
        \Redis::REDIS_SET       => 'set',
        \Redis::REDIS_LIST      => 'list',
        \Redis::REDIS_ZSET      => 'zset',
        \Redis::REDIS_HASH      => 'hash',
        \Redis::REDIS_STREAM    => 'stream',
    ];

    /**
     * Get all data types.
     *
     * Used in form.
     *
     * @return array<string, string>
     */
    public function getAllTypes(): array {
        static $types = [];

        unset($this->data_types[\Redis::REDIS_NOT_FOUND], $this->data_types[\Redis::REDIS_STREAM]);

        foreach ($this->data_types as $type) {
            $types[$type] = ucfirst($type);
        }

        return $types;
    }

    /**
     * Get a key type.
     *
     * @throws RedisException|DashboardException
     */
    public function getType(string $key): string {
        $type = $this->type($key);

        if (!isset($this->data_types[$type])) {
            throw new DashboardException(sprintf('Unsupported data type: %s', $type));
        }

        return $this->data_types[$type];
    }

    /**
     * Alias to a lRem() but with the same order of parameters.
     *
     * @throws RedisException
     */
    public function listRem(string $key, string $value, int $count): int {
        return $this->lRem($key, $value, $count);
    }

    /**
     * Get server info.
     *
     * @param string|null $option
     *
     * @return array<int|string, mixed>
     * @throws RedisException
     */
    public function getInfo(string $option = null): array {
        static $info = [];

        $options = ['SERVER', 'CLIENTS', 'MEMORY', 'PERSISTENCE', 'STATS', 'REPLICATION', 'CPU', 'CLUSTER', 'KEYSPACE'];

        foreach ($options as $option_name) {
            $info[strtolower($option_name)] = $this->info($option_name);
        }

        return $info[$option] ?? $info;
    }

    /**
     * Fix for PHPRedis 6 and newer.
     *
     * @return array<int, mixed>
     *
     * @throws RedisException
     */
    public function zSetRange(string $key, int $start, int $stop): array {
        if (version_compare(phpversion('redis'), '6.0', '>=')) {
            $start = (string) $start;
            $stop = (string) $stop;
        }

        return $this->zRange($key, $start, $stop);
    }
}
