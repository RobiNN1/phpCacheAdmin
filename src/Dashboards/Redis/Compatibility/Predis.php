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
     * Get all data types.
     *
     * Used in form.
     *
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
     * Get a key type.
     *
     * @param string $key
     *
     * @return string
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
     * Alias to a lRem() but with the same order of parameters.
     *
     * @param string $key
     * @param string $value
     * @param int    $count
     *
     * @return int
     */
    public function listRem(string $key, string $value, int $count): int {
        return $this->lrem($key, $count, $value);
    }

    /**
     * Get server info.
     *
     * @param string|null $option
     *
     * @return array<int|string, mixed>
     */
    public function getInfo(string $option = null): array {
        static $array = [];

        foreach (['Server', 'Clients', 'Memory', 'Persistence', 'Stats', 'Replication', 'CPU', 'Cluster', 'Keyspace'] as $option_name) {
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
     * Get a range of messages from a given stream.
     *
     * @param string $stream
     * @param string $start
     * @param string $end
     *
     * @return array<int|string, mixed>
     */
    public function xRange(string $stream, string $start, string $end): array {
        return $this->executeRaw(['XRANGE', $stream, $start, $end]);
    }

    /**
     * Add a message to a stream.
     *
     * @param string             $key
     * @param string             $id
     * @param array<int, string> $messages
     * @param int                $maxLen
     * @param bool               $isApproximate
     *
     * @return string
     */
    public function xAdd(string $key, string $id, array $messages, int $maxLen = 0, bool $isApproximate = false): string {
        return $this->executeRaw(['XADD', $key, $id, $messages, $maxLen, $isApproximate]);
    }

    /**
     * Delete one or more messages from a stream.
     *
     * @param string             $key
     * @param array<int, string> $ids
     *
     * @return int
     */
    public function xDel(string $key, array $ids): int {
        return $this->executeRaw(['XDEL', $key, implode(' ', $ids)]);
    }

    /**
     * Get the length of a given stream.
     *
     * @param string $stream
     *
     * @return int
     */
    public function xLen(string $stream): int {
        return (int) $this->executeRaw(['XLEN', $stream]);
    }

    /**
     *  Execute any generic command.
     *
     * @param string $command
     * @param mixed  ...$arguments
     *
     * @return mixed
     */
    public function rawCommand(string $command, ...$arguments) {
        return $this->executeRaw(func_get_args());
    }
}
