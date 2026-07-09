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
use Predis\Cluster\RedisStrategy;
use Predis\Collection\Iterator\Keyspace;
use Predis\Command\RawCommand;
use Predis\Response\Status;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisCompatibilityInterface;
use RobiNN\Pca\Dashboards\Redis\Compatibility\RedisExtra;
use RuntimeException;
use Throwable;

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
        'ReJSON-RL' => 'json',
    ];

    /**
     * @param array<string, mixed> $server
     *
     * @throws DashboardException
     */
    public function __construct(private readonly array $server) {
        $cluster_options = ['cluster' => 'redis'];

        if (isset($this->server['password'])) {
            $cluster_options['parameters']['password'] = $this->server['password'];

            if (isset($this->server['username'])) {
                $cluster_options['parameters']['username'] = $this->server['username'];
            }
        }

        try {
            parent::__construct($this->server['nodes'], $cluster_options);
            $this->connect();

            foreach ($this->server['nodes'] as $node) {
                $this->nodes[] = new PredisClient('tcp://'.$node, $cluster_options);
            }
        } catch (Exception $e) {
            throw new DashboardException($e->getMessage().' ['.implode(', ', $this->server['nodes']).']', $e->getCode(), $e);
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
        static $aggregated = null;

        if ($aggregated !== null) {
            $info = $this->aggregatedData($aggregated, $combine);

            return $option !== null ? ($info[strtolower($option)] ?? []) : $info;
        }

        $aggregated = [];

        foreach ($this->nodes as $node) {
            try {
                $node_info = $this->parseInfoOutput((string) $node->executeRaw(['INFO', 'all']));
            } catch (Exception) {
                continue;
            }

            foreach ($node_info as $section => $section_data) {
                foreach ($section_data as $key => $value) {
                    $aggregated[$section][$key][] = $value;
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
            foreach (new Keyspace($node, $pattern, $count) as $item) {
                $keys[] = $item;

                if (count($keys) >= $count) {
                    return $keys;
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

        $results = $this->pipeline(static function ($pipe) use ($keys, $script_sha): void {
            foreach ($keys as $key) {
                $pipe->evalsha($script_sha, 1, $key);
            }
        });

        if (!is_array($results)) {
            return [];
        }

        $data = [];

        foreach (array_values($keys) as $i => $key) {
            $result = $results[$i] ?? null;

            if (is_array($result) && count($result) >= 3) {
                $data[$key] = [
                    'ttl'   => $result[0],
                    'type'  => $this->data_types[(string) $result[1]] ?? $result[1],
                    'size'  => $result[2] ?? 0,
                    'count' => isset($result[3]) && is_numeric($result[3]) ? (int) $result[3] : null,
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

    public function commandExists(string $command): bool {
        $info = $this->nodes[0]->executeRaw(['COMMAND', 'INFO', $command]);

        return is_array($info[0] ?? null);
    }

    public function restoreKeys(string $key, int $ttl, string $value): bool {
        return (string) $this->restore($key, $ttl, $value) === 'OK';
    }

    /**
     * @throws Exception
     */
    public function jsonGet(string $key): string {
        return (string) $this->json('jsonget', $key, [$key]);
    }

    /**
     * @throws Exception
     */
    public function jsonSet(string $key, mixed $path): bool {
        return (string) $this->json('jsonset', $key, [$key, '$', $path]) === 'OK';
    }

    /**
     * Predis cannot route JSON.* commands on its own, so the slot is computed from the key and set on the (native) command explicitly.
     *
     * @param array<int, mixed> $arguments
     *
     * @throws Exception
     */
    private function json(string $id, string $key, array $arguments): mixed {
        $command = $this->createCommand($id, $arguments);
        $command->setSlot((new RedisStrategy())->getSlotByKey($key));

        try {
            return $this->executeCommand($command);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    protected function moduleList(): mixed {
        return $this->nodes[0]->executeRaw(['MODULE', 'LIST']);
    }

    /**
     * @return array{channels: array<string, int>, patterns: int}
     */
    public function pubSubStats(string $pattern = '*'): array {
        $channels = [];
        $patterns = 0;

        foreach ($this->nodes as $node) {
            try {
                $node_channels = $node->executeRaw(['PUBSUB', 'CHANNELS', $pattern]);

                if (is_array($node_channels) && $node_channels !== []) {
                    $numsub = $node->executeRaw(array_merge(['PUBSUB', 'NUMSUB'], $node_channels));

                    foreach ($this->parseNumSubReply(is_array($numsub) ? $numsub : []) as $channel => $subscribers) {
                        $channels[$channel] = ($channels[$channel] ?? 0) + $subscribers;
                    }
                }

                $numpat = $node->executeRaw(['PUBSUB', 'NUMPAT']);
                $patterns += is_numeric($numpat) ? (int) $numpat : 0;
            } catch (Exception) {
                continue;
            }
        }

        return ['channels' => $channels, 'patterns' => $patterns];
    }

    public function publishMessage(string $channel, string $message): int {
        return (int) $this->nodes[0]->executeRaw(['PUBLISH', $channel, $message]);
    }

    /**
     * Messages are broadcast to all cluster nodes, so subscribing to a single node is enough.
     *
     * @return array<int, array{channel: string, message: string, time: int}>
     */
    public function captureMessages(string $pattern, int $seconds, int $limit): array {
        [$host, $port] = explode(':', (string) $this->server['nodes'][0]) + [1 => '6379'];

        $server = ['host' => $host, 'port' => (int) $port];

        foreach (['username', 'password'] as $key) {
            if (isset($this->server[$key])) {
                $server[$key] = $this->server[$key];
            }
        }

        return (new Predis($server))->captureMessages($pattern, $seconds, $limit);
    }

    /**
     * @param array<int, string> $args
     *
     * @throws Throwable
     */
    public function consoleCommand(array $args): mixed {
        $reply = $this->executeCommand(RawCommand::create(...$args));

        return $reply instanceof Status ? $reply->getPayload() : $reply;
    }
}
