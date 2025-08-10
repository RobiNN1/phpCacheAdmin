<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster;

use Exception;
use InvalidArgumentException;
use Predis\Client as PredisClient;
use Predis\Collection\Iterator\Keyspace;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisCompatibilityInterface;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisJson;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisModules;

class PredisCluster extends PredisClient implements RedisCompatibilityInterface {
    use RedisJson;
    use RedisModules;

    /**
     * @var array<int, PredisClient>
     */
    private array $nodes;

    /**
     * @var array<int|string, string>
     */
    public array $data_types = [
        'none'      => 'other',
        'string'    => 'string',
        'set'       => 'set',
        'list'      => 'list',
        'zset'      => 'zset',
        'hash'      => 'hash',
        'stream'    => 'stream',
        'ReJSON-RL' => 'rejson',
    ];

    /**
     * @param array<string, mixed> $server
     *
     * @throws DashboardException
     */
    public function __construct(array $server) {
        $cluster_options = ['cluster' => 'redis'];

        if (isset($server['password'])) {
            $cluster_options['parameters']['password'] = $server['password'];
        }

        try {
            parent::__construct($server['nodes'], $cluster_options);
            $this->connect();

            foreach ($server['nodes'] as $node) {
                $this->nodes[] = new PredisClient('tcp://'.$node, $cluster_options);
            }
        } catch (Exception $e) {
            throw new DashboardException($e->getMessage().' ['.implode(', ', $server['nodes']).']');
        }
    }

    public function getType(string|int $type): string {
        return $this->data_types[$type] ?? 'unknown';
    }

    public function getKeyType(string $key): string {
        $type = $this->type($key);

        if ($type === 'none') {
            $type = $this->executeRaw(['TYPE', $key]);
        }

        return $this->getType($type);
    }

    /**
     * @param list<string>|null $combine
     *
     * @return array<string, array<string, mixed>>
     */
    public function getInfo(?string $option = null, ?array $combine = null): array {
        static $info = [];

        $options = ['Server', 'Clients', 'Memory', 'Persistence', 'Stats', 'Replication', 'CPU', 'Cluster', 'Keyspace'];

        foreach ($options as $option_name) {
            /** @var array<string, array<int, mixed>|array<string, array<int, mixed>>> $combined */
            $combined = [];

            foreach ($this->nodes as $node) {
                /** @var array<string, mixed> $node_info */
                $node_info = $node->info()[$option_name];

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
     */
    public function keys(string $pattern): array {
        $keys = [];

        foreach ($this->nodes as $node) {
            foreach ($node->keys($pattern) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<int, string>
     */
    public function scanKeys(string $pattern, int $count): array {
        $keys = [];

        foreach ($this->nodes as $node) {
            foreach (new Keyspace($node, $pattern) as $item) {
                $keys[] = $item;

                if (count($keys) === $count) {
                    break;
                }
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
        $lua_script = file_get_contents(__DIR__.'/../get_key_info.lua');

        if ($lua_script === false) {
            return [];
        }

        $script_sha = null;

        foreach ($this->nodes as $node) {
            $script_sha = $node->script('load', $lua_script);
        }

        if (empty($script_sha)) {
            return [];
        }

        $data = [];

        foreach ($keys as $key) {
            $results = $this->evalsha($script_sha, 1, $key);

            if (is_array($results) && count($results) >= 3) {
                $data[$key] = [
                    'ttl'   => $results[0], 'type' => $results[1], 'size' => $results[2] ?? 0,
                    'count' => isset($results[3]) && is_numeric($results[3]) ? (int) $results[3] : null,
                ];
            }
        }

        return $data;
    }

    public function size(string $key): int {
        foreach ($this->nodes as $node) {
            $size = $node->executeRaw(['MEMORY', 'USAGE', $key]);
            if ($size !== false && $size !== null) {
                return (int) $size;
            }
        }

        return 0;
    }

    public function flushDatabase(): bool {
        foreach ($this->nodes as $node) {
            $node->flushdb();
        }

        return true;
    }

    public function databaseSize(): int {
        $total = 0;

        foreach ($this->nodes as $node) {
            $total += $node->dbsize();
        }

        return $total;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function execConfig(string $operation, mixed ...$args): mixed {
        switch (strtoupper($operation)) {
            case 'GET':
                if ($args === []) {
                    throw new InvalidArgumentException('CONFIG GET requires a parameter name.');
                }

                $result = $this->nodes[0]->executeRaw(['CONFIG', 'GET', $args[0]]);

                return isset($result[0], $result[1]) ? [$result[0] => $result[1]] : [];
            case 'SET':
                if (count($args) < 2) {
                    throw new InvalidArgumentException('CONFIG SET requires a parameter name and a value.');
                }

                foreach ($this->nodes as $node) {
                    $node->executeRaw(['CONFIG', 'SET', $args[0], $args[1]]);
                }

                return true;
            case 'REWRITE':
            case 'RESETSTAT':
                foreach ($this->nodes as $node) {
                    $node->executeRaw(['CONFIG', strtoupper($operation)]);
                }

                return true;
            default:
                throw new InvalidArgumentException('Unsupported CONFIG operation: '.$operation);
        }
    }

    /**
     * @return null|array<int, mixed>
     */
    public function getSlowlog(int $count): ?array {
        $all_logs = [];

        foreach ($this->nodes as $node) {
            $logs = $node->executeRaw(['SLOWLOG', 'GET', (string) $count]);

            if (is_array($logs) && $logs !== []) {
                array_push($all_logs, ...$logs);
            }
        }

        usort($all_logs, static fn ($a, $b): int => $b[1] <=> $a[1]);

        return $all_logs;
    }

    public function resetSlowlog(): bool {
        foreach ($this->nodes as $node) {
            $node->slowlog('RESET');
        }

        return true;
    }
}
