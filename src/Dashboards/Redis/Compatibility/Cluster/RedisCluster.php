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
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis as PhpRedis;
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
        'ReJSON-RL'            => 'json',
    ];

    /**
     * @param array<string, mixed> $server
     *
     * @throws DashboardException
     */
    public function __construct(private readonly array $server) {
        $auth = null;

        if (isset($this->server['password'])) {
            $auth = isset($this->server['username']) ? [$this->server['username'], $this->server['password']] : $this->server['password'];
        }

        try {
            parent::__construct($this->server['name'] ?? 'default', $this->server['nodes'], 3, 0, false, $auth);
        } catch (RedisClusterException $e) {
            throw new DashboardException($e->getMessage().' ['.implode(',', $this->server['nodes']).']', $e->getCode(), $e);
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
        if ($this->info_cache !== null) {
            $info = $this->aggregatedData($this->info_cache, $combine);

            return $option !== null ? ($info[strtolower($option)] ?? []) : $info;
        }

        $aggregated = [];

        foreach ($this->nodes as $node) {
            try {
                $node_info = $this->parseInfoOutput((string) $this->rawcommand($node, 'INFO', 'all'));
            } catch (RedisClusterException) {
                continue;
            }

            foreach ($node_info as $section => $section_data) {
                foreach ($section_data as $key => $value) {
                    $aggregated[$section][$key][] = $value;
                }
            }
        }

        $this->info_cache = $aggregated;
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
                    'type'  => $this->data_types[(string) $results[1]] ?? $results[1],
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

    /**
     * @throws RedisClusterException
     */
    public function resetSlowlog(): bool {
        foreach ($this->nodes as $node) {
            $this->rawcommand($node, 'SLOWLOG', 'RESET');
        }

        return true;
    }

    /**
     * @throws RedisClusterException
     */
    public function commandExists(string $command): bool {
        $info = $this->rawcommand($this->nodes[0], 'COMMAND', 'INFO', $command);

        return is_array($info[0] ?? null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getClients(): array {
        $clients = [];

        foreach ($this->nodes as $node) {
            try {
                $self_id = $this->rawcommand($node, 'CLIENT', 'ID');
                $node_clients = $this->parseClientList(
                    (string) $this->rawcommand($node, 'CLIENT', 'LIST'),
                    is_numeric($self_id) ? (string) $self_id : null,
                    implode(':', (array) $node)
                );
            } catch (RedisClusterException) {
                continue;
            }

            if ($node_clients !== []) {
                array_push($clients, ...$node_clients);
            }
        }

        return $clients;
    }

    public function killClient(string $id): bool {
        $killed = false;

        foreach ($this->nodes as $node) {
            try {
                $killed = (int) $this->rawcommand($node, 'CLIENT', 'KILL', 'ID', $id) > 0 || $killed;
            } catch (RedisClusterException) {
                continue;
            }
        }

        return $killed;
    }

    /**
     * @throws RedisClusterException
     */
    public function restoreKeys(string $key, int $ttl, string $value): bool {
        return $this->restore($key, $ttl, $value);
    }

    /**
     * @throws RedisClusterException
     */
    public function jsonGet(string $key): string {
        return (string) $this->rawcommand($key, 'JSON.GET', $key);
    }

    /**
     * @throws RedisClusterException
     */
    public function jsonSet(string $key, mixed $value): bool {
        $raw = $this->rawcommand($key, 'JSON.SET', $key, '$', $value);

        return $raw === true || $raw === 'OK';
    }

    /**
     * @throws RedisClusterException
     */
    protected function moduleList(): mixed {
        return $this->rawcommand($this->nodes[0], 'MODULE', 'LIST');
    }

    /**
     * @return array{channels: array<string, int>, patterns: int}
     */
    public function pubSubStats(string $pattern = '*'): array {
        $channels = [];
        $patterns = 0;

        foreach ($this->nodes as $node) {
            try {
                $node_channels = $this->rawcommand($node, 'PUBSUB', 'CHANNELS', $pattern);

                if (is_array($node_channels) && $node_channels !== []) {
                    $numsub = $this->rawcommand($node, 'PUBSUB', 'NUMSUB', ...$node_channels);

                    foreach ($this->parseNumSubReply(is_array($numsub) ? $numsub : []) as $channel => $subscribers) {
                        $channels[$channel] = ($channels[$channel] ?? 0) + $subscribers;
                    }
                }

                $numpat = $this->rawcommand($node, 'PUBSUB', 'NUMPAT');
                $patterns += is_numeric($numpat) ? (int) $numpat : 0;
            } catch (RedisClusterException) {
                continue;
            }
        }

        return ['channels' => $channels, 'patterns' => $patterns];
    }

    /**
     * @throws RedisClusterException
     */
    public function publishMessage(string $channel, string $message): int {
        $receivers = $this->publish($channel, $message);

        return is_int($receivers) ? $receivers : 0;
    }

    /**
     * Messages are broadcast to all cluster nodes, so subscribing to a single node is enough.
     *
     * @return array<int, array{channel: string, message: string, time: int}>
     */
    public function captureMessages(string $pattern, int $seconds, int $limit): array {
        [$host, $port] = $this->nodes[0];

        $server = ['host' => $host, 'port' => (int) $port];

        foreach (['username', 'password'] as $key) {
            if (isset($this->server[$key])) {
                $server[$key] = $this->server[$key];
            }
        }

        try {
            return (new PhpRedis($server))->captureMessages($pattern, $seconds, $limit);
        } catch (DashboardException) {
            return [];
        }
    }

    /**
     * @param array<int, string> $args
     *
     * @throws RedisClusterException
     */
    public function consoleCommand(array $args): mixed {
        $this->setOption(Redis::OPT_REPLY_LITERAL, true);
        $this->clearLastError();

        $target = $args[1] ?? $this->nodes[0];
        $reply = $this->rawcommand($target, ...$args);

        if ($reply === false && ($error = $this->getLastError()) !== null) {
            throw new RedisClusterException($error);
        }

        return $reply;
    }
}
