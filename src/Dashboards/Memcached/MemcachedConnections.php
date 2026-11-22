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

        foreach ($clients as &$client) {
            // On a log scale up to a day, a minute is already a third of the bar.
            $client['idle_percent'] = round(log10(max(0, $client['idle']) + 1) / log10(86_401) * 100, 2);
            $client['state_title'] = self::CONNECTION_STATES[$client['state']] ?? '';
        }

        unset($client);

        return [
            'connections' => $clients,
            'listeners'   => array_column($listeners, 'addr'),
            'states'      => $this->countBy($clients, static fn (array $client): string => $client['state']),
            'hosts'       => array_slice($this->countBy($clients, $this->connectionHost(...)), 0, 10),
            'state_names' => self::CONNECTION_STATES,
        ];
    }

    /**
     * What each state means, for a tooltip.
     *
     * @var array<string, string>
     */
    private const CONNECTION_STATES = [
        'conn_new_cmd'   => 'Idle, waiting for the next command',
        'conn_waiting'   => 'Waiting for the client to send data',
        'conn_read'      => 'Reading a command',
        'conn_parse_cmd' => 'Parsing a command',
        'conn_nread'     => 'Reading the value of a storage command',
        'conn_swallow'   => 'Discarding a value that was refused',
        'conn_write'     => 'Writing a reply',
        'conn_mwrite'    => 'Writing a reply of several parts',
        'conn_closing'   => 'Closing',
        'conn_closed'    => 'Closed',
        'conn_watch'     => 'Streaming a watch',
        'conn_io_queue'  => 'Waiting for background IO (extstore, proxy)',
    ];

    /**
     * The client's host without its port, e.g. "tcp:10.0.0.5:51234" becomes "10.0.0.5".
     *
     * @param array<string, mixed> $connection
     */
    private function connectionHost(array $connection): string {
        $address = (string) $connection['addr'];

        if (preg_match('/^(?:tcp6?|udp6?):(.+):\d+$/', $address, $match) === 1) {
            return trim($match[1], '[]');
        }

        return $address === '' ? 'unknown' : $address;
    }

    /**
     * How many connections share a value, most common first.
     *
     * @param array<int, array<string, mixed>>     $connections
     * @param callable(array<string, mixed>): string $value
     *
     * @return array<string, int>
     */
    private function countBy(array $connections, callable $value): array {
        $counts = [];

        foreach ($connections as $connection) {
            $name = $value($connection);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
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
