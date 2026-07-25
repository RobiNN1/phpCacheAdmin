<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use JsonException;

trait RedisLive {
    /**
     * Fields that are summed across all nodes in cluster mode.
     *
     * @var array<int, string>
     */
    private array $live_combine = [
        'uptime_in_seconds',
        'used_memory',
        'used_memory_peak',
        'mem_fragmentation_ratio',
        'keyspace_hits',
        'keyspace_misses',
        'connected_clients',
        'instantaneous_ops_per_sec',
    ];

    /**
     * @param array<int|string, mixed>            $info
     * @param array<string, array<string, mixed>> $command_stats
     *
     * @return array<string, mixed>
     */
    public function liveSnapshot(array $info, array $command_stats): array {
        // In cluster mode, values that differ between nodes and are not summed come back as an array.
        $number = static fn (mixed $value): int|float => is_numeric($value) ? $value + 0 : 0;

        $stats = $info['stats'] ?? [];
        $memory = $info['memory'] ?? [];
        $clients = $info['clients'] ?? [];
        $server = $info['server'] ?? [];

        $calls = [];

        foreach ($command_stats as $command => $details) {
            $calls[str_replace('cmdstat_', '', $command)] = (int) $number($details['calls'] ?? null);
        }

        return [
            'time'                => microtime(true),
            'timestamp'           => date('Y-m-d H:i:s'),
            'uptime'              => (int) $number($server['uptime_in_seconds'] ?? null),
            'commands_per_second' => (int) $number($stats['instantaneous_ops_per_sec'] ?? null),
            'keyspace'            => [
                'hits'   => (int) $number($stats['keyspace_hits'] ?? null),
                'misses' => (int) $number($stats['keyspace_misses'] ?? null),
            ],
            'memory'              => [
                'used'          => (int) $number($memory['used_memory'] ?? null),
                'peak'          => (int) $number($memory['used_memory_peak'] ?? null),
                'fragmentation' => (float) $number($memory['mem_fragmentation_ratio'] ?? null),
            ],
            'connections'         => (int) $number($clients['connected_clients'] ?? null),
            'commands_stats'      => $calls,
        ];
    }

    /**
     * @throws JsonException
     */
    private function liveAjax(): string {
        $snapshot = $this->liveSnapshot(
            $this->redis->getInfo(null, $this->live_combine),
            $this->redis->parseSectionData('commandstats')
        );

        header('Content-Type: application/json');

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }
}
