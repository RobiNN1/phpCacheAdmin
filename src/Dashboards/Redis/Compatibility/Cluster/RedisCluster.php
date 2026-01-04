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
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisExtra;

class RedisCluster extends \RedisCluster implements RedisCompatibilityInterface {
    use RedisExtra;

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
     */
    public function getInfo(?string $option = null, ?array $combine = null): array {
        static $info = null;

        if ($info !== null) {
            return $option !== null ? ($info[strtolower($option)] ?? []) : $info;
        }

        $aggregated = [];

        foreach ($this->nodes as $node) {
            foreach ($this->getInfoSections() as $section_name) {
                try {
                    $node_section_info = $this->info($node, $section_name);

                    if (!is_array($node_section_info)) {
                        continue;
                    }

                    $section_lower = strtolower($section_name);

                    foreach ($node_section_info as $key => $value) {
                        if ($section_lower === 'commandstats' || $section_lower === 'keyspace') {
                            $aggregated[$section_lower][$key][] = $value;
                            continue;
                        }

                        if (is_array($value)) {
                            foreach ($value as $sub_key => $sub_val) {
                                $aggregated[$section_lower][$key][$sub_key][] = $sub_val;
                            }
                        } else {
                            $aggregated[$section_lower][$key][] = $value;
                        }
                    }
                } catch (RedisClusterException) {
                    continue;
                }
            }
        }

        $info = $this->aggregatedData($aggregated, $combine);

        return $option !== null ? ($info[strtolower($option)] ?? []) : $info;
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

        usort($all_logs, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return $all_logs;
    }

    public function resetSlowlog(): bool {
        foreach ($this->nodes as $node) {
            $this->rawcommand($node, 'SLOWLOG', 'RESET');
        }

        return true;
    }

    /**
     * @return array<int, string>
     *
     * @throws RedisClusterException
     */
    public function getCommands(): array {
        $commands = $this->rawcommand($this->nodes[0], 'COMMAND');

        return array_column($commands, 0);
    }

    /**
     * @throws RedisClusterException
     */
    public function restoreKeys(string $key, int $ttl, string $value): bool {
        return $this->restore($key, $ttl, $value);
    }
}
