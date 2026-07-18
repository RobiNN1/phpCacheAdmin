<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Predis\Client as PredisClient;
use RedisSentinel;
use RobiNN\Pca\Dashboards\DashboardException;
use Throwable;

class Sentinel {
    public const DEFAULT_PORT = 26379;

    public const DEFAULT_MASTER = 'mymaster';

    /**
     * @param array<string, mixed> $server
     */
    public function __construct(private readonly array $server, private readonly string $client) {
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function isConfigured(array $server): bool {
        return !empty($server['sentinels']) && is_array($server['sentinels']);
    }

    /**
     * @return array{host: string, port: int}
     *
     * @throws DashboardException
     */
    public function masterAddress(): array {
        $master_name = (string) ($this->server['sentinelmaster'] ?? self::DEFAULT_MASTER);
        $errors = [];

        foreach ($this->server['sentinels'] as $address) {
            [$host, $port] = $this->splitAddress((string) $address);

            try {
                $master = $this->client === 'redis' ? $this->askPhpRedis($host, $port, $master_name) : $this->askPredis($host, $port, $master_name);
            } catch (Throwable $e) {
                $errors[] = $address.': '.$e->getMessage();

                continue;
            }

            if ($master !== null) {
                return $master;
            }

            $errors[] = $address.': does not monitor "'.$master_name.'"';
        }

        throw new DashboardException('No sentinel could resolve the master. ('.implode('; ', $errors).')');
    }

    /**
     * @return array{host: string, port: int}|null
     */
    private function askPhpRedis(string $host, int $port, string $master_name): ?array {
        $options = ['host' => $host, 'port' => $port, 'connectTimeout' => 3];

        if (isset($this->server['sentinelpassword'])) {
            $options['auth'] = $this->server['sentinelpassword'];
        }

        return $this->parseReply((new RedisSentinel($options))->getMasterAddrByName($master_name));
    }

    /**
     * @return array{host: string, port: int}|null
     */
    private function askPredis(string $host, int $port, string $master_name): ?array {
        $parameters = ['host' => $host, 'port' => $port, 'timeout' => 3];

        if (isset($this->server['sentinelpassword'])) {
            $parameters['password'] = $this->server['sentinelpassword'];
        }

        return $this->parseReply((new PredisClient($parameters))->executeRaw(['SENTINEL', 'get-master-addr-by-name', $master_name]));
    }

    /**
     * @return array{host: string, port: int}|null
     */
    private function parseReply(mixed $reply): ?array {
        if (!is_array($reply) || !isset($reply[0], $reply[1])) {
            return null;
        }

        return ['host' => (string) $reply[0], 'port' => (int) $reply[1]];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitAddress(string $address): array {
        $separator = strrpos($address, ':');

        if ($separator === false) {
            return [$address, self::DEFAULT_PORT];
        }

        return [substr($address, 0, $separator), (int) substr($address, $separator + 1)];
    }
}
