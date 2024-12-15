<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use RedisException;
use RobiNN\Pca\Dashboards\DashboardException;

class Redis extends \Redis implements RedisCompatibilityInterface {
    use RedisJson;
    use RedisModules;

    /**
     * @var array<int|string, string>
     */
    public array $data_types = [
        self::REDIS_NOT_FOUND => 'other',
        self::REDIS_STRING    => 'string',
        self::REDIS_SET       => 'set',
        self::REDIS_LIST      => 'list',
        self::REDIS_ZSET      => 'zset',
        self::REDIS_HASH      => 'hash',
        self::REDIS_STREAM    => 'stream',
        'ReJSON-RL'           => 'rejson',
    ];

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
                $server['scheme'] ??= 'tcp';

                $this->connect($server['scheme'].'://'.$server['host'], (int) $server['port'], 3, null, 0, 0, [
                    'stream' => $server['ssl'] ?? [],
                ]);
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

    public function getType(string|int $type): string {
        return $this->data_types[$type] ?? 'unknown';
    }

    /**
     * @throws RedisException
     */
    public function getKeyType(string $key): string {
        $type = $this->type($key);

        if ($type === self::REDIS_NOT_FOUND) {
            $this->setOption(self::OPT_REPLY_LITERAL, true);
            $type = $this->rawcommand('TYPE', $key);
        }

        return $this->getType($type);
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws RedisException
     */
    public function getInfo(?string $option = null): array {
        static $info = [];

        $options = ['SERVER', 'CLIENTS', 'MEMORY', 'PERSISTENCE', 'STATS', 'REPLICATION', 'CPU', 'CLUSTER', 'KEYSPACE'];

        foreach ($options as $option_name) {
            $info[strtolower($option_name)] = $this->info($option_name);
        }

        return $info[$option] ?? $info;
    }

    /**
     * @return array<int, string>
     *
     * @throws RedisException
     */
    public function scanKeys(string $pattern, int $count): array {
        $keys = [];

        $iterator = null;

        while (false !== ($scan = $this->scan($iterator, $pattern, $count))) {
            foreach ($scan as $key) {
                $keys[] = $key;
            }

            if (count($keys) === $count) {
                break;
            }
        }

        return $keys;
    }

    /**
     * @throws RedisException
     */
    public function listRem(string $key, string $value, int $count): int {
        return $this->lrem($key, $value, $count);
    }

    /**
     * @param array<string, string> $messages
     *
     * @throws RedisException
     */
    public function streamAdd(string $key, string $id, array $messages): string {
        return $this->xadd($key, $id, $messages);
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     *
     * @throws RedisException
     */
    public function pipelineKeys(array $keys): array {
        $pipe = $this->multi(self::PIPELINE);

        foreach ($keys as $key) {
            $pipe->ttl($key);
            $pipe->type($key);
            $pipe->rawcommand('MEMORY', 'USAGE', $key);
            $pipe->rawcommand('SCARD', $key);
            $pipe->rawcommand('LLEN', $key);
            $pipe->rawcommand('ZCARD', $key);
            $pipe->rawcommand('HLEN', $key);
            $pipe->rawcommand('XLEN', $key);
        }

        $results = $pipe->exec();

        $data = [];

        foreach ($keys as $i => $key) {
            $index = $i * 8; // index + count of pipeline commands
            $type = $this->getType($results[$index + 1]);

            $count = match ($type) {
                'set' => $results[$index + 3] ?? null,
                'list' => $results[$index + 4] ?? null,
                'zset' => $results[$index + 5] ?? null,
                'hash' => $results[$index + 6] ?? null,
                'stream' => $results[$index + 7] ?? null,
                default => null,
            };

            $data[$key] = [
                'ttl'   => $results[$index],
                'type'  => $type,
                'size'  => $results[$index + 2] ?? 0,
                'count' => is_numeric($count) ? (int) $count : null,
            ];
        }

        return $data;
    }

    public function size(string $key): int {
        $size = $this->rawcommand('MEMORY', 'USAGE', $key);

        return is_int($size) ? $size : 0;
    }
}
