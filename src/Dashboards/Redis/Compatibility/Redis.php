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
     * @param array<string, mixed> $server
     *
     * @throws DashboardException
     */
    public function __construct(array $server) {
        parent::__construct();

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
                $credentials = isset($server['username']) ? [$server['username'], $server['password']] : $server['password'];

                $this->auth($credentials);
            }

            $this->select($server['database'] ?? 0);
        } catch (RedisException $e) {
            $connection = $server['path'] ?? $server['host'].':'.$server['port'];
            throw new DashboardException($e->getMessage().' ['.$connection.']');
        }
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
        $lua_script = file_get_contents(__DIR__.'/get_key_info.lua');

        if ($lua_script === false) {
            return [];
        }

        $script_sha = $this->script('load', $lua_script);

        if (!$script_sha) {
            return [];
        }

        $pipe = $this->pipeline();

        foreach ($keys as $key) {
            $pipe->evalsha($script_sha, [$key], 1);
        }

        $results = $pipe->exec();

        $data = [];

        foreach ($keys as $i => $key) {
            $result = $results[$i] ?? null;

            if (!is_array($result) || count($result) < 3) {
                continue;
            }

            $data[$key] = [
                'ttl'   => $result[0],
                'type'  => $result[1],
                'size'  => $result[2] ?? 0,
                'count' => isset($result[3]) && is_numeric($result[3]) ? (int) $result[3] : null,
            ];
        }

        return $data;
    }

    public function size(string $key): int {
        $size = $this->rawcommand('MEMORY', 'USAGE', $key);

        return is_int($size) ? $size : 0;
    }

    public function flushDatabase(): bool {
        return $this->flushDB();
    }

    public function databaseSize(): int {
        return $this->dbSize();
    }

    public function execConfig(string $operation, mixed ...$args): mixed {
        return $this->config($operation, ...$args);
    }

    /**
     * @return null|array<int, mixed>
     */
    public function getSlowlog(int $count): ?array {
        return $this->slowlog('GET', $count);
    }

    public function resetSlowlog(): bool {
        return $this->slowlog('RESET');
    }

    public function isCommandSupported(string $command): bool {
        try {
            $commands = $this->rawcommand('COMMAND');
            $command_names = array_column($commands, 0);
            $is_supported = in_array(strtolower($command), $command_names, true);
        } catch (RedisException) {
            $is_supported = false;
        }

        return $is_supported;
    }
}
