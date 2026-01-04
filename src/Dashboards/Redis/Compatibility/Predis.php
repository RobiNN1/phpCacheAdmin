<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Predis\Client;
use Predis\Collection\Iterator\Keyspace;

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
        static $info = null;

        if ($info === null) {
            $section_info = [];

            foreach ($this->getInfoSections() as $section) {
                $response = $this->info($section);

                $section_data = (is_array($response) && $response !== []) ? reset($response) : null;

                if ($section_data && is_array($section_data)) {
                    if ($section === 'keyspace') {
                        $reformatted_keyspace = [];

                        foreach ($section_data as $db => $keys_data_array) {
                            $key_value_pairs = [];

                            if (is_array($keys_data_array)) {
                                foreach ($keys_data_array as $key => $value) {
                                    $key_value_pairs[] = $key.'='.$value;
                                }
                            }

                            $reformatted_keyspace[$db] = implode(',', $key_value_pairs);
                        }

                        $section_data = $reformatted_keyspace;
                    }

                    $section_info[strtolower($section)] = $section_data;
                }
            }

            $info = $section_info;
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
                'type'  => $result[1],
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

    /**
     * @return array<int, string>
     */
    public function getCommands(): array {
        $commands = $this->rawcommand('COMMAND');

        return array_column($commands, 0);
    }

    public function restoreKeys(string $key, int $ttl, string $value): bool {
        return (string) $this->restore($key, $ttl, $value) === 'OK';
    }
}
