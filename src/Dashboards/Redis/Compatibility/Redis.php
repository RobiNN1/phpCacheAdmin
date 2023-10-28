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

                /* @phpstan-ignore-next-line phpstan doesn't know about the last $context parameter - phpredis >= 5.3 */
                $this->connect($server['scheme'].'://'.$server['host'], (int) $server['port'], 3, null, 0, 0, [
                    'stream' => $server['ssl'] ?? null,
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

    /**
     * @throws RedisException|DashboardException
     */
    public function getType(string $key): string {
        $type = $this->type($key);

        if ($type === self::REDIS_NOT_FOUND) {
            $this->setOption(self::OPT_REPLY_LITERAL, true);
            $type = $this->rawCommand('TYPE', $key);
        }

        if (!isset($this->data_types[$type])) {
            throw new DashboardException(sprintf('Unsupported data type: %s', $type));
        }

        return $this->data_types[$type];
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
        return $this->lRem($key, $value, $count);
    }

    /**
     * @param array<string, string> $messages
     *
     * @throws RedisException
     */
    public function streamAdd(string $key, string $id, array $messages): string {
        return $this->xAdd($key, $id, $messages);
    }
}
