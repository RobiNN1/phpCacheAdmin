<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Exception;
use JsonException;
use RedisException;
use RobiNN\Pca\Dashboards\DashboardException;

class Redis extends \Redis implements RedisCompatibilityInterface {
    use RedisExtra;

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
        self::REDIS_VECTORSET => 'vectorset',
        'ReJSON-RL'           => 'json',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $server;

    /**
     * @param array<string, mixed> $server
     *
     * @throws DashboardException
     */
    public function __construct(array $server) {
        parent::__construct();

        $server['port'] ??= 6379;
        $server['scheme'] ??= 'tcp';
        $this->server = $server;

        $this->connectToServer();
    }

    /**
     * @throws DashboardException
     */
    private function connectToServer(): void {
        $server = $this->server;

        try {
            if (isset($server['path'])) {
                $this->connect($server['path']);
            } else {
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
            throw new DashboardException($e->getMessage().' ['.$connection.']', $e->getCode(), $e);
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
        if ($this->info_cache === null) {
            try {
                $this->info_cache = $this->parseInfoOutput((string) $this->rawcommand('INFO', 'all'));
            } catch (RedisException) {
                $this->info_cache = [];
            }
        }

        if ($option !== null) {
            return $this->info_cache[strtolower($option)] ?? [];
        }

        return $this->info_cache;
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

                if (count($keys) >= $count) {
                    return $keys;
                }
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
     * @return array<int, array<string, mixed>>
     *
     * @throws RedisException
     */
    public function streamGroups(string $key): array {
        return $this->xinfo('GROUPS', $key) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RedisException
     */
    public function streamConsumers(string $key, string $group): array {
        return $this->xinfo('CONSUMERS', $key, $group) ?: [];
    }

    /**
     * @return array<int, mixed>
     *
     * @throws RedisException
     */
    public function streamPending(string $key, string $group): array {
        return $this->xpending($key, $group) ?: [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RedisException
     */
    public function streamReadGroup(string $key, string $group, string $consumer, int $count): array {
        return $this->xreadgroup($group, $consumer, [$key => '>'], $count) ?: [];
    }

    /**
     * @throws RedisException
     */
    public function streamCreateGroup(string $key, string $group, string $id = '0'): bool {
        return (bool) $this->xgroup('CREATE', $key, $group, $id);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RedisException
     */
    public function vectorInfo(string $key): array {
        $info = $this->vinfo($key);

        return is_array($info) ? $info : [];
    }

    /**
     * @return array<int, string>
     *
     * @throws RedisException
     */
    public function vectorMembers(string $key, int $count): array {
        $members = $this->vrandmember($key, $count);

        return is_array($members) ? array_map(strval(...), $members) : [];
    }

    /**
     * @return array<int, float>
     *
     * @throws RedisException
     */
    public function vectorEmbedding(string $key, string $element): array {
        return $this->parseVectorEmbedding($this->vemb($key, $element));
    }

    /**
     * @throws RedisException
     *
     * @throws JsonException
     */
    public function vectorAttributes(string $key, string $element): string {
        $attributes = $this->vgetattr($key, $element);

        if (is_array($attributes)) {
            $attributes = json_encode($attributes, JSON_THROW_ON_ERROR);
        }

        return is_string($attributes) ? $attributes : '';
    }

    /**
     * @param array<int, float|string> $vector
     *
     * @throws RedisException
     */
    public function vectorAdd(string $key, string $element, array $vector, string $attributes = ''): bool {
        return (bool) $this->vadd($key, $vector, $element, $attributes !== '' ? ['SETATTR' => $attributes] : null);
    }

    /**
     * @throws RedisException
     */
    public function vectorRem(string $key, string $element): bool {
        return (bool) $this->vrem($key, $element);
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
            if (!is_array($result)) {
                continue;
            }

            if (count($result) < 3) {
                continue;
            }

            $data[$key] = [
                'ttl'   => $result[0],
                'type'  => $this->data_types[(string) $result[1]] ?? $result[1],
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

    public function commandExists(string $command): bool {
        $info = $this->rawcommand('COMMAND', 'INFO', $command);

        return is_array($info[0] ?? null);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RedisException
     */
    public function getClients(): array {
        $self_id = $this->rawcommand('CLIENT', 'ID');

        return $this->parseClientList(
            (string) $this->rawcommand('CLIENT', 'LIST'),
            is_numeric($self_id) ? (string) $self_id : null
        );
    }

    /**
     * @throws RedisException
     */
    public function killClient(string $id): bool {
        return (int) $this->rawcommand('CLIENT', 'KILL', 'ID', $id) > 0;
    }

    public function restoreKeys(string $key, int $ttl, string $value): bool {
        return $this->restore($key, $ttl, $value);
    }

    /**
     * @throws RedisException
     */
    public function jsonGet(string $key): string {
        return (string) $this->rawcommand('JSON.GET', $key);
    }

    /**
     * @throws RedisException
     */
    public function jsonSet(string $key, mixed $value): bool {
        $raw = $this->rawcommand('JSON.SET', $key, '$', $value);

        return $raw === true || $raw === 'OK';
    }

    /**
     * @return array{channels: array<string, int>, patterns: int}
     *
     * @throws RedisException
     */
    public function pubSubStats(string $pattern = '*'): array {
        $channels = $this->rawcommand('PUBSUB', 'CHANNELS', $pattern);
        $numsub = is_array($channels) && $channels !== [] ? $this->rawcommand('PUBSUB', 'NUMSUB', ...$channels) : [];
        $numpat = $this->rawcommand('PUBSUB', 'NUMPAT');

        return [
            'channels' => $this->parseNumSubReply(is_array($numsub) ? $numsub : []),
            'patterns' => is_numeric($numpat) ? (int) $numpat : 0,
        ];
    }

    /**
     * @throws RedisException
     */
    public function publishMessage(string $channel, string $message): int {
        $receivers = $this->publish($channel, $message);

        return is_int($receivers) ? $receivers : 0;
    }

    /**
     * @return array<int, array{channel: string, message: string, time: int}>
     */
    public function captureMessages(string $pattern, int $seconds, int $limit): array {
        $messages = [];
        $start = microtime(true);

        try {
            $this->setOption(self::OPT_READ_TIMEOUT, $seconds);
            $this->psubscribe([$pattern], static function ($redis, string $p, string $channel, string $message) use (&$messages, $start, $seconds, $limit): void {
                $messages[] = ['channel' => $channel, 'message' => $message, 'time' => time()];

                if (count($messages) >= $limit || (microtime(true) - $start) >= $seconds) {
                    $redis->punsubscribe([$p]);
                }
            });
        } catch (RedisException) {
        } finally {
            try {
                $this->close();
                $this->setOption(self::OPT_READ_TIMEOUT, 0);
                $this->connectToServer();
            } catch (Exception) {
            }
        }

        return array_slice($messages, 0, $limit);
    }

    /**
     * @param array<int, string> $args
     *
     * @throws RedisException
     */
    public function consoleCommand(array $args): mixed {
        $this->setOption(self::OPT_REPLY_LITERAL, true);
        $this->clearLastError();

        $command = array_shift($args);
        $reply = $this->rawcommand($command, ...$args);

        if ($reply === false && ($error = $this->getLastError()) !== null) {
            throw new RedisException($error);
        }

        return $reply;
    }
}
