<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use Predis\Client as Predis;
use RobiNN\Pca\Format;

trait RedisPanels {
    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(): array {
        if ($this->client === 'redis') {
            $title = 'Redis extension v'.phpversion('redis');
        } elseif ($this->client === 'predis') {
            $title = 'Predis v'.Predis::VERSION;
        }

        try {
            $info = $this->redis->getInfo(null, [
                'redis_version',
                'valkey_version',
                'used_memory',
                'maxmemory',
                'keyspace_hits',
                'keyspace_misses',
                'total_connections_received',
                'total_commands_processed',
            ]);

            $panels = [
                $this->mainPanel($info, $title ?? null),
                $this->memoryPanel($info),
                $this->statsPanel($info),
            ];

            $panels = array_filter($panels);

            return array_values($panels);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @return array{title: ?string, data: array<int|string, mixed>}
     *
     * @throws Exception
     */
    private function mainPanel(array $info, ?string $title): array {
        $server_info = $info['server'] ?? [];
        $cluster_info = $info['cluster'] ?? [];
        $replication_info = $info['replication'] ?? [];
        $stats_info = $info['stats'] ?? [];

        $hits = (int) ($stats_info['keyspace_hits'] ?? 0);
        $misses = (int) ($stats_info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        $hit_rate = $total > 0 ? round(($hits / $total) * 100, 2) : 0;

        $role = null;

        if (!$this->is_cluster && isset($replication_info['role'])) {
            $slaves = $replication_info['connected_slaves'] ?? 0;
            $role = ['Role', $replication_info['role'].', connected slaves '.$slaves];
        }

        if (isset($server_info['valkey_version'])) {
            $version = 'Valkey '.$server_info['valkey_version'].' (Redis '.($server_info['redis_version'] ?? 'N/A').')';
            $mode = $server_info['server_mode'] ?? null;
        } elseif (str_contains((strtolower($server_info['executable'] ?? '')), 'keydb')) {
            $version = 'KeyDB '.($server_info['redis_version'] ?? 'N/A');
            $mode = $server_info['redis_mode'] ?? null;
        } else {
            $version = $server_info['redis_version'] ?? 'N/A';
            $mode = $server_info['redis_mode'] ?? null;
        }

        $sentinel = null;

        if ($this->is_sentinel) {
            $master_name = $this->servers[$this->current_server]['sentinelmaster'] ?? 'mymaster';
            $sentinel = ['Sentinel', $master_name.' at '.$this->sentinel_master];
        }

        $data = [
            'Version' => $version.($mode !== null ? ', '.$mode.' mode' : ''),
            $sentinel,
            'Cluster' => ($cluster_info['cluster_enabled'] ?? 0) ? 'Enabled' : 'Disabled',
            'Uptime'  => Format::seconds((int) ($server_info['uptime_in_seconds'] ?? 0), false),
            $role,
            'Keys'    => Format::number($this->getKeysCountFromInfo($info)).' (all databases)',
            ['Hits / Misses', Format::number($hits).' / '.Format::number($misses).' ('.$hit_rate.'%)', $hit_rate, 'higher'],
        ];

        return [
            'title' => $title,
            'data'  => array_filter($data),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @throws Exception
     */
    private function getKeysCountFromInfo(array $info): int {
        $count_of_all_keys = 0;

        if (!$this->is_cluster) {
            $keyspace_info = $info['keyspace'] ?? [];

            foreach ($keyspace_info as $entry) {
                if (is_string($entry) && str_contains($entry, 'keys=')) {
                    parse_str(str_replace(',', '&', $entry), $parsed);
                    $count_of_all_keys += (int) ($parsed['keys'] ?? 0);
                }
            }
        } else {
            $count_of_all_keys = $this->redis->databaseSize();
        }

        return $count_of_all_keys;
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @return array{title: string, data: array<int|string, mixed>}|null
     */
    private function memoryPanel(array $info): ?array {
        if (!isset($info['memory'])) {
            return null;
        }

        $memory_info = $info['memory'];
        $used_memory = (int) ($memory_info['used_memory']);
        $max_memory = (int) ($memory_info['maxmemory']);
        $used_memory_formatted = ['Used', Format::bytes($used_memory)];

        if ($max_memory > 0) {
            $memory_usage = round(($used_memory / $max_memory) * 100, 2);
            $used_memory_formatted = ['Used', Format::bytes($used_memory).' ('.$memory_usage.'%)', $memory_usage];
        }

        return [
            'title' => 'Memory',
            'data'  => [
                'Total'               => $max_memory > 0 ? Format::bytes($max_memory, 0) : '∞',
                $used_memory_formatted,
                'Free'                => $max_memory > 0 ? Format::bytes($max_memory - $used_memory) : '∞',
                'Peak memory usage'   => Format::bytes((int) ($memory_info['used_memory_peak'] ?? 0)),
                'Fragmentation ratio' => $memory_info['mem_fragmentation_ratio'] ?? 'N/A',
                'Lua memory usage'    => Format::bytes((int) ($memory_info['used_memory_lua'] ?? 0)),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @return array{title: string, data: array<int|string, mixed>}|null
     */
    private function statsPanel(array $info): ?array {
        if (!isset($info['stats'], $info['clients'])) {
            return null;
        }

        $stats_info = $info['stats'];
        $clients_info = $info['clients'];
        $maxclients = isset($clients_info['maxclients']) ? ' / '.Format::number((int) $clients_info['maxclients']) : '';

        return [
            'title' => 'Stats',
            'data'  => [
                'Connected clients'            => Format::number((int) ($clients_info['connected_clients'] ?? 0)).$maxclients,
                'Blocked clients'              => Format::number((int) ($clients_info['blocked_clients'] ?? 0)),
                'Total connections received'   => Format::number((int) ($stats_info['total_connections_received'] ?? 0)),
                'Total commands processed'     => Format::number((int) ($stats_info['total_commands_processed'] ?? 0)),
                'Instantaneous ops per second' => Format::number((int) ($stats_info['instantaneous_ops_per_sec'] ?? 0)),
            ],
        ];
    }
}
