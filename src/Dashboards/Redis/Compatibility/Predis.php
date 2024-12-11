<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Predis\Client;
use Predis\Collection\Iterator\Keyspace;

class Predis extends Client implements RedisCompatibilityInterface {
    use RedisJson;
    use RedisModules;

    /**
     * @var array<string, string>
     */
    public array $data_types = [
        'none'      => 'none',
        'other'     => 'other',
        'string'    => 'string',
        'set'       => 'set',
        'list'      => 'list',
        'zset'      => 'zset',
        'hash'      => 'hash',
        'stream'    => 'stream',
        'ReJSON-RL' => 'rejson',
    ];

    /**
     * @param array<string, int|string> $server
     */
    public function __construct(array $server) {
        if (isset($server['path'])) {
            $connect = [
                'scheme' => 'unix',
                'path'   => $server['path'],
            ];
        } else {
            $connect = [
                'scheme' => $server['scheme'] ?? 'tcp',
                'host'   => $server['host'],
                'port'   => $server['port'] ??= 6379,
                'ssl'    => $server['ssl'] ?? null,
            ];
        }

        parent::__construct($connect + [
                'database' => $server['database'] ?? 0,
                'username' => $server['username'] ?? null,
                'password' => $server['password'] ?? null,
            ]);
    }

    public function getType(string|int $type): string {
        return $this->data_types[$type] ?? 'unknown';
    }

    public function getKeyType(string $key): string {
        $type = (string) $this->type($key);

        return $this->getType($type);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getInfo(?string $option = null): array {
        static $array = [];

        $options = ['Server', 'Clients', 'Memory', 'Persistence', 'Stats', 'Replication', 'CPU', 'Cluster', 'Keyspace'];

        foreach ($options as $option_name) {
            $data = $this->info()[$option_name];

            if ($option_name === 'Keyspace') {
                foreach ($data as $db => $keys_data) {
                    $keys = [];
                    foreach ($keys_data as $key_name => $key_value) {
                        $keys[] = $key_name.'='.$key_value;
                    }

                    $data[$db] = implode(',', $keys);
                }
            }

            $array[strtolower($option_name)] = $data;
        }

        return $array[$option] ?? $array;
    }

    /**
     * @return array<int, string>
     */
    public function scanKeys(string $pattern, int $count): array {
        $keys = [];

        foreach (new Keyspace($this, $pattern) as $item) {
            $keys[] = $item;

            if (count($keys) === $count) {
                break;
            }
        }

        return $keys;
    }

    public function listRem(string $key, string $value, int $count): int {
        return $this->lrem($key, $count, $value);
    }

    /**
     * @param array<string, string> $messages
     */
    public function streamAdd(string $key, string $id, array $messages): string {
        return $this->xadd($key, $messages, $id);
    }

    public function rawcommand(string $command, mixed ...$arguments): mixed {
        return $this->executeRaw(func_get_args());
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     */
    public function pipelineKeys(array $keys): array {
        $results = $this->pipeline(function ($pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->ttl($key);
                $pipe->type($key);
                $pipe->eval('return redis.call("MEMORY", "USAGE", KEYS[1])', 1, $key);
            }
        });

        $data = [];

        foreach ($keys as $i => $key) {
            $index = $i * 3;
            $data[$key] = [
                'ttl'   => $results[$index],
                'type'  => (string) $results[$index + 1],
                'size'  => is_int($results[$index + 2]) ? $results[$index + 2] : 0,
                'count' => null,
            ];
        }

        $type_results = $this->pipeline(function ($pipe) use ($keys, $data): void {
            foreach ($keys as $key) {
                $lua_script = match ($data[$key]['type']) {
                    'set' => 'return redis.call("SCARD", KEYS[1])',
                    'list' => 'return redis.call("LLEN", KEYS[1])',
                    'zset' => 'return redis.call("ZCARD", KEYS[1])',
                    'hash' => 'return redis.call("HLEN", KEYS[1])',
                    'stream' => 'return redis.call("XLEN", KEYS[1])',
                    default => 'return nil',
                };

                $pipe->eval($lua_script, 1, $key);
            }
        });

        foreach ($keys as $i => $key) {
            $type = $data[$key]['type'];
            $data[$key]['count'] = ($type !== 'none' && isset($type_results[$i]) && is_int($type_results[$i])) ? $type_results[$i] : null;
        }

        return $data;
    }

    public function size(string $key): int {
        $size = $this->executeRaw(['MEMORY', 'USAGE', $key]);

        return is_int($size) ? $size : 0;
    }
}
