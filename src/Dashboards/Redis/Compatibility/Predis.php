<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Exception;
use Predis\Client;
use Predis\Collection\Iterator\Keyspace;
use Predis\Command\RawCommand;
use Predis\Response\Status;
use RuntimeException;
use Throwable;

/**
 * @method bool restore(string $key, int $ttl, string $value)
 */
class Predis extends Client implements RedisCompatibilityInterface {
    use RedisExtra;

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
        'ReJSON-RL' => 'json',
    ];

    /**
     * @param array<string, mixed> $server
     */
    public function __construct(private array $server) {
        if (isset($this->server['path'])) {
            $connect = [
                'scheme' => 'unix',
                'path'   => $this->server['path'],
            ];
        } else {
            $connect = [
                'scheme' => $this->server['scheme'] ?? 'tcp',
                'host'   => $this->server['host'],
                'port'   => $this->server['port'] ??= 6379,
                'ssl'    => $this->server['ssl'] ?? null,
            ];
        }

        if (isset($this->server['read_write_timeout'])) {
            $connect['read_write_timeout'] = $this->server['read_write_timeout'];
        }

        parent::__construct($connect + [
                'database' => $this->server['database'] ?? 0,
                'username' => $this->server['username'] ?? null,
                'password' => $this->server['password'] ?? null,
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
        static $info = null;

        if ($info === null) {
            try {
                $info = $this->parseInfoOutput((string) $this->executeRaw(['INFO', 'all']));
            } catch (Exception) {
                $info = [];
            }
        }

        if ($option !== null) {
            return $info[strtolower($option)] ?? [];
        }

        return $info;
    }

    /**
     * @return array<int, string>
     */
    public function scanKeys(string $pattern, int $count): array {
        $keys = [];

        foreach (new Keyspace($this, $pattern, $count) as $item) {
            $keys[] = $item;

            if (count($keys) >= $count) {
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
        $lua_script = file_get_contents(__DIR__.'/get_key_info.lua');

        if ($lua_script === false) {
            return [];
        }

        $script_sha = $this->script('load', $lua_script);

        if (!$script_sha) {
            return [];
        }

        $results = $this->pipeline(function ($pipe) use ($keys, $script_sha): void {
            foreach ($keys as $key) {
                $pipe->evalsha($script_sha, 1, $key);
            }
        });

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
        $size = $this->executeRaw(['MEMORY', 'USAGE', $key]);

        return is_int($size) ? $size : 0;
    }

    public function flushDatabase(): bool {
        return (string) $this->flushdb() === 'OK';
    }

    public function databaseSize(): int {
        return $this->dbsize();
    }

    public function execConfig(string $operation, mixed ...$args): mixed {
        return $this->config($operation, ...$args);
    }

    /**
     * @return null|array<int, mixed>
     */
    public function getSlowlog(int $count): ?array {
        return $this->rawcommand('SLOWLOG', 'GET', (string) $count);
    }

    public function resetSlowlog(): bool {
        return (string) $this->slowlog('RESET') === 'OK';
    }

    public function commandExists(string $command): bool {
        $info = $this->rawcommand('COMMAND', 'INFO', $command);

        return is_array($info[0] ?? null);
    }

    public function restoreKeys(string $key, int $ttl, string $value): bool {
        return (string) $this->restore($key, $ttl, $value) === 'OK';
    }

    /**
     * @throws Exception
     */
    public function jsonGet(string $key): string {
        return (string) $this->json('jsonget', [$key]);
    }

    /**
     * @throws Exception
     */
    public function jsonSet(string $key, mixed $path): bool {
        return (string) $this->json('jsonset', [$key, '$', $path]) === 'OK';
    }

    /**
     * Run a native JSON command.
     *
     * @param array<int, mixed> $arguments
     *
     * @throws Exception
     */
    private function json(string $id, array $arguments): mixed {
        try {
            return $this->executeCommand($this->createCommand($id, $arguments));
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * @return array{channels: array<string, int>, patterns: int}
     */
    public function pubSubStats(string $pattern = '*'): array {
        $channels = $this->executeRaw(['PUBSUB', 'CHANNELS', $pattern]);
        $numsub = is_array($channels) && $channels !== [] ? $this->executeRaw(array_merge(['PUBSUB', 'NUMSUB'], $channels)) : [];
        $numpat = $this->executeRaw(['PUBSUB', 'NUMPAT']);

        return [
            'channels' => $this->parseNumSubReply(is_array($numsub) ? $numsub : []),
            'patterns' => is_numeric($numpat) ? (int) $numpat : 0,
        ];
    }

    public function publishMessage(string $channel, string $message): int {
        return $this->publish($channel, $message);
    }

    /**
     * @return array<int, array{channel: string, message: string, time: int}>
     */
    public function captureMessages(string $pattern, int $seconds, int $limit): array {
        $messages = [];
        $start = microtime(true);

        // A separate connection with a read timeout, so the blocking subscription can end.
        $client = new self(['read_write_timeout' => $seconds] + $this->server);

        try {
            $pubsub = $client->pubSubLoop();
            $pubsub->psubscribe($pattern);

            foreach ($pubsub as $message) {
                if ($message->kind === 'pmessage') {
                    $messages[] = ['channel' => (string) $message->channel, 'message' => (string) $message->payload, 'time' => time()];
                }

                if (count($messages) >= $limit || (microtime(true) - $start) >= $seconds) {
                    break;
                }
            }
        } catch (Exception) {
        }

        $client->disconnect();

        return $messages;
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
