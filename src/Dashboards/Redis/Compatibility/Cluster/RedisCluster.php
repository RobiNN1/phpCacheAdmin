<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster;

use InvalidArgumentException;
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
     * @var array<int, mixed>
     */
    private array $nodes;

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

        $this->nodes = $this->_masters();
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
     * @param list<string>|null $combine
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws RedisClusterException
     */
    public function getInfo(?string $option = null, ?array $combine = null): array {
        static $info = [];

        $options = ['SERVER', 'CLIENTS', 'MEMORY', 'PERSISTENCE', 'STATS', 'REPLICATION', 'CPU', 'CLUSTER', 'KEYSPACE'];

        foreach ($options as $option_name) {
            /** @var array<string, array<int, mixed>|array<string, array<int, mixed>>> $combined */
            $combined = [];

            foreach ($this->nodes as $node) {
                /** @var array<string, mixed> $node_info */
                $node_info = $this->info($node, $option_name);

                foreach ($node_info as $key => $value) {
                    if (is_array($value)) {
                        foreach ($value as $sub_key => $sub_val) {
                            $combined[$key][$sub_key][] = $sub_val;
                        }
                    } else {
                        $combined[$key][] = $value;
                    }
                }
            }

            foreach ($combined as $key => $values) {
                if (is_array(reset($values))) {
                    foreach ($values as $sub_key => $sub_values) {
                        $combined[$key][$sub_key] = $this->combineValues($sub_key, $sub_values, $combine);
                    }
                } else {
                    $combined[$key] = $this->combineValues($key, $values, $combine);
                }
            }

            $info[strtolower($option_name)] = $combined;
        }

        return $option !== null ? ($info[$option] ?? []) : $info;
    }

    /**
     * @param list<mixed>       $values
     * @param list<string>|null $combine
     */
    private function combineValues(string $key, array $values, ?array $combine): mixed {
        $unique = array_unique($values);

        if (count($unique) === 1) {
            return $unique[0];
        }

        $numeric = array_filter($values, 'is_numeric');

        if ($combine && in_array($key, $combine, true) && count($numeric) === count($values)) {
            return array_sum($values);
        }

        if ($key === 'mem_fragmentation_ratio' && count($numeric) === count($values)) {
            return round(array_sum($values) / count($values), 2);
        }

        if ($key === 'used_memory_peak' && count($numeric) === count($values)) {
            return max($values);
        }

        return $values;
    }

    /**
     * @return array<int, string>
     *
     * @throws RedisClusterException
     */
    public function scanKeys(string $pattern, int $count): array {
        $keys = [];

        foreach ($this->nodes as $node) {
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
        $lua_script = file_get_contents(__DIR__.'/../get_key_info.lua');

        if ($lua_script === false) {
            return [];
        }

        foreach ($this->nodes as $master) {
            $sha = $this->script($master, 'load', $lua_script);

            if ($sha) {
                $script_sha = $sha;
            }
        }

        if (empty($script_sha)) {
            return [];
        }

        $data = [];

        foreach ($keys as $key) {
            $results = $this->evalsha($script_sha, [$key], 1);

            if (is_array($results) && count($results) >= 3) {
                $data[$key] = [
                    'ttl'   => $results[0],
                    'type'  => $results[1],
                    'size'  => $results[2] ?? 0,
                    'count' => isset($results[3]) && is_numeric($results[3]) ? (int) $results[3] : null,
                ];
            }
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
    public function flushDatabase(): bool {
        foreach ($this->nodes as $node) {
            $this->flushDB($node);
        }

        return true;
    }

    /**
     * @throws RedisClusterException
     */
    public function databaseSize(): int {
        $nodes = $this->_masters();
        $total = 0;

        foreach ($nodes as $node) {
            $total += $this->dbSize($node);
        }

        return $total;
    }

    /**
     * @throws RedisClusterException|InvalidArgumentException
     */
    public function execConfig(string $operation, mixed ...$args): mixed {
        switch (strtoupper($operation)) {
            case 'GET':
                if ($args === []) {
                    throw new InvalidArgumentException('CONFIG GET requires a parameter name.');
                }

                $result = $this->rawcommand($this->nodes[0], 'CONFIG', 'GET', $args[0]);

                return isset($result[0], $result[1]) ? [$result[0] => $result[1]] : [];
            case 'SET':
                if (count($args) < 2) {
                    throw new InvalidArgumentException('CONFIG SET requires a parameter name and a value.');
                }

                foreach ($this->nodes as $node) {
                    $this->rawcommand($node, 'CONFIG', 'SET', $args[0], $args[1]);
                }

                return true;
            case 'REWRITE':
            case 'RESETSTAT':
                foreach ($this->nodes as $node) {
                    $this->rawcommand($node, 'CONFIG', strtoupper($operation));
                }

                return true;
            default:
                throw new InvalidArgumentException('Unsupported CONFIG operation: '.$operation);
        }
    }

    /**
     * @return null|array<int, mixed>
     *
     * @throws RedisClusterException
     */
    public function getSlowlog(int $count): ?array {
        $all_logs = [];

        foreach ($this->nodes as $node) {
            $logs = $this->rawcommand($node, 'SLOWLOG', 'GET', $count);

            if (is_array($logs) && $logs !== []) {
                array_push($all_logs, ...$logs);
            }
        }

        usort($all_logs, static fn ($a, $b): int => $b[1] <=> $a[1]);

        return $all_logs;
    }

    public function resetSlowlog(): bool {
        foreach ($this->nodes as $node) {
            $this->rawcommand($node, 'SLOWLOG', 'RESET');
        }

        return true;
    }

    public function isCommandSupported(string $command): bool {
        try {
            $commands = $this->rawcommand($this->nodes[0], 'COMMAND');
            $command_names = array_column($commands, 0);
            $is_supported = in_array(strtolower($command), $command_names, true);
        } catch (RedisClusterException) {
            $is_supported = false;
        }

        return $is_supported;
    }
}
