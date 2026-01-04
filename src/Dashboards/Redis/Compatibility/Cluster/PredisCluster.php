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
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisExtra;

/**
 * @method bool restore(string $key, int $ttl, string $value)
 */
class PredisCluster extends PredisClient implements RedisCompatibilityInterface {
    use RedisExtra;

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
        static $info = null;

        if ($info !== null) {
            return $option !== null ? ($info[strtolower($option)] ?? []) : $info;
        }

        $aggregated = [];

        foreach ($this->nodes as $node) {
            foreach ($this->getInfoSections() as $section_name) {
                try {
                    $response = $node->info($section_name);
                    $node_section_info = (is_array($response) && $response !== []) ? reset($response) : null;

                    if (!$node_section_info || !is_array($node_section_info)) {
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
                } catch (Exception) {
                    continue;
                }
            }
        }

        $info = $this->aggregatedData($aggregated, $combine);

        return $option !== null ? ($info[strtolower($option)] ?? []) : $info;
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
        return $this->nodes[0]->executeRaw(func_get_args());
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

        usort($all_logs, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return $all_logs;
    }

    public function resetSlowlog(): bool {
        foreach ($this->nodes as $node) {
            $node->slowlog('RESET');
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function getCommands(): array {
        $commands = $this->nodes[0]->executeRaw(['COMMAND']);

        return array_column($commands, 0);
    }

    public function restoreKeys(string $key, int $ttl, string $value): bool {
        return (string) $this->restore($key, $ttl, $value) === 'OK';
    }
}
