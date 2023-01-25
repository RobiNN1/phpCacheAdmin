<?php
/**
 * This file is part of phpCacheAdmin.
 *
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Predis\Client;
use Predis\Collection\Iterator\Keyspace;
use RobiNN\Pca\Dashboards\DashboardException;

class Predis extends Client implements CompatibilityInterface {
    /**
     * @var array<string, string>
     */
    private array $data_types = [
        'none'   => 'none',
        'other'  => 'other',
        'string' => 'string',
        'set'    => 'set',
        'list'   => 'list',
        'zset'   => 'zset',
        'hash'   => 'hash',
        'stream' => 'stream',
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
                'host' => $server['host'],
                'port' => $server['port'] ??= 6379,
            ];
        }

        parent::__construct($connect + [
                'database' => $server['database'] ?? 0,
                'username' => $server['username'] ?? null,
                'password' => $server['password'] ?? null,
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function getAllTypes(): array {
        static $types = [];

        unset($this->data_types['none'], $this->data_types['other'], $this->data_types['stream']);

        foreach ($this->data_types as $type) {
            $types[$type] = ucfirst($type);
        }

        return $types;
    }

    /**
     * @throws DashboardException
     */
    public function getType(string $key): string {
        $type = (string) $this->type($key);

        if (!isset($this->data_types[$type])) {
            throw new DashboardException(sprintf('Unsupported data type: %s', $type));
        }

        return $this->data_types[$type];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getInfo(?string $option = null): array {
        static $array = [];

        $options = ['Server', 'Clients', 'Memory', 'Persistence', 'Stats', 'Replication', 'CPU', 'Cluster', 'Keyspace'];

        foreach ($options as $option_name) {
            $data = $this->info()[$option_name];

            if ($option_name === 'Keyspace') {
                foreach ($data as $db => $keys_data) {
                    $keys = [];
                    foreach ($keys_data as $key_name => $key_value) {
                        $keys[] = $key_name.'='.$key_value;
                    }

                    $data[$db] = implode(',', $keys);
                }
            }

            $array[strtolower($option_name)] = $data;
        }

        return $array[$option] ?? $array;
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

    /**
     * @param mixed ...$arguments
     *
     * @return mixed
     */
    public function rawCommand(string $command, ...$arguments) {
        return $this->executeRaw(func_get_args());
    }
}
