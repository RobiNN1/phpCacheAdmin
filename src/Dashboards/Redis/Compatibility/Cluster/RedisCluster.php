<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster;

use Redis;
use RedisClusterException;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisCompatibilityInterface;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisJson;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisModules;

class RedisCluster extends \RedisCluster implements RedisCompatibilityInterface {
    use RedisJson;
    use RedisModules;

    /**
     * @var array<int|string, string>
     */
    public array $data_types = [
        Redis::REDIS_NOT_FOUND => 'other',
        Redis::REDIS_STRING    => 'string',
        Redis::REDIS_SET       => 'set',
        Redis::REDIS_LIST      => 'list',
        Redis::REDIS_ZSET      => 'zset',
        Redis::REDIS_HASH      => 'hash',
        Redis::REDIS_STREAM    => 'stream',
        'ReJSON-RL'            => 'rejson',
    ];

    /**
     * @param array<string, mixed> $server
     *
     * @throws DashboardException
     */
    public function __construct(array $server) {
        $auth = null;

        if (isset($server['password'])) {
            $auth = isset($server['username']) ? [$server['username'], $server['password']] : $server['password'];
        }

        try {
            parent::__construct($server['name'] ?? 'default', $server['nodes'], 3, 0, false, $auth);
        } catch (RedisClusterException $e) {
            throw new DashboardException($e->getMessage().' ['.implode(',', $server['nodes']).']');
        }
    }

    public function getType(string|int $type): string {
        return $this->data_types[$type] ?? 'unknown';
    }

    /**
     * @throws RedisClusterException
     */
    public function getKeyType(string $key): string {
        $type = $this->type($key);

        if ($type === Redis::REDIS_NOT_FOUND) {
            $this->setOption(Redis::OPT_REPLY_LITERAL, true);
            $type = $this->rawcommand($key, 'TYPE', $key);
        }

        return $this->getType($type);
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws RedisClusterException
     */
    public function getInfo(?string $option = null): array {
        static $info = [];

        $options = ['SERVER', 'CLIENTS', 'MEMORY', 'PERSISTENCE', 'STATS', 'REPLICATION', 'CPU', 'CLUSTER', 'KEYSPACE'];
        $nodes = $this->_masters();

        foreach ($options as $option_name) {
            $combined = [];

            foreach ($nodes as $node) {
                $node_info = $this->info($node, $option_name);

                foreach ($node_info as $key => $value) {
                    $combined[$key][] = $value;
                }
            }

            foreach ($combined as $key => $values) {
                $unique = array_unique($values);
                $combined[$key] = count($unique) === 1 ? $unique[0] : $unique;
            }

            $info[strtolower($option_name)] = $combined;
        }

        return $option !== null ? ($info[$option] ?? []) : $info;
    }

    /**
     * @return array<int, string>
     * @throws RedisClusterException
     */
    public function scanKeys(string $pattern, int $count): array {
        $keys = [];
        $nodes = $this->_masters();

        foreach ($nodes as $node) {
            $iterator = null;

            while (false !== ($scan = $this->scan($iterator, $node, $pattern, $count))) {
                foreach ($scan as $key) {
                    $keys[] = $key;

                    if (count($keys) >= $count) {
                        return $keys;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * @throws RedisClusterException
     */
    public function listRem(string $key, string $value, int $count): int {
        return $this->lrem($key, $value, $count);
    }

    /**
     * @param array<string, string> $messages
     */
    public function streamAdd(string $key, string $id, array $messages): string {
        return $this->xadd($key, $id, $messages);
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     *
     * @throws RedisClusterException
     */
    public function pipelineKeys(array $keys): array {
        $data = [];

        foreach ($keys as $key) {
            $ttl = $this->ttl($key);
            $type = $this->type($key);
            $size = $this->rawcommand($key, 'MEMORY', 'USAGE', $key);
            $scard = $this->rawcommand($key, 'SCARD', $key);
            $llen = $this->rawcommand($key, 'LLEN', $key);
            $zcard = $this->rawcommand($key, 'ZCARD', $key);
            $hlen = $this->rawcommand($key, 'HLEN', $key);
            $xlen = $this->rawcommand($key, 'XLEN', $key);

            $results = [$ttl, $type, $size, $scard, $llen, $zcard, $hlen, $xlen];

            $type = $this->getType($results[1]);

            $count = match ($type) {
                'set' => $results[3] ?? null,
                'list' => $results[4] ?? null,
                'zset' => $results[5] ?? null,
                'hash' => $results[6] ?? null,
                'stream' => $results[7] ?? null,
                default => null,
            };

            $data[$key] = [
                'ttl'   => $results[0],
                'type'  => $type,
                'size'  => $results[2] ?? 0,
                'count' => is_numeric($count) ? (int) $count : null,
            ];
        }

        return $data;
    }

    /**
     * @throws RedisClusterException
     */
    public function size(string $key): int {
        $size = $this->rawcommand($key, 'MEMORY', 'USAGE', $key);

        return is_int($size) ? $size : 0;
    }

    /**
     * @throws RedisClusterException
     */
    public function flushAllClusterDBs(): bool {
        $nodes = $this->_masters();

        foreach ($nodes as $node) {
            $this->flushDB($node);
        }

        return true;
    }

    /**
     * @throws RedisClusterException
     */
    public function clusterDbSize(): int {
        $nodes = $this->_masters();
        $total = 0;

        foreach ($nodes as $node) {
            $total += $this->dbSize($node);
        }

        return $total;
    }
}
