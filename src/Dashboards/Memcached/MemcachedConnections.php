<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

trait MemcachedConnections {
    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function connectionsTab(): array {
        $connections = $this->parseConnections($this->memcached->getServerStats('conns'));

        if ($connections === []) {
            return ['tab_error' => 'The server did not report any connections, "stats conns" requires Memcached >= 1.4.18.'];
        }

        $listeners = array_values(array_filter($connections, static fn (array $connection): bool => $connection['listening']));
        $clients = array_values(array_filter($connections, static fn (array $connection): bool => !$connection['listening']));

        // Idle first, those are the ones worth looking at.
        usort($clients, static fn (array $a, array $b): int => $b['idle'] <=> $a['idle']);

        return ['connections' => $clients, 'listeners' => array_column($listeners, 'addr')];
    }

    /**
     * One group of fields per file descriptor, e.g. "STAT 27:addr tcp:127.0.0.1:53054".
     *
     * @param array<string, mixed> $stats
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseConnections(array $stats): array {
        $connections = [];

        foreach ($stats as $key => $value) {
            if (!str_contains($key, ':')) {
                continue;
            }

            [$fd, $field] = explode(':', $key, 2);

            if (is_numeric($fd)) {
                $connections[(int) $fd][$field] = $value;
            }
        }

        return array_values(array_map(static function (array $connection, int $fd): array {
            $state = (string) ($connection['state'] ?? '');

            return [
                'fd'          => $fd,
                'addr'        => (string) ($connection['addr'] ?? ''),
                // A listening socket was not accepted on another one.
                'listen_addr' => (string) ($connection['listen_addr'] ?? ''),
                'state'       => $state,
                'idle'        => (int) ($connection['secs_since_last_cmd'] ?? 0),
                'listening'   => $state === 'conn_listening',
            ];
        }, $connections, array_keys($connections)));
    }
}
