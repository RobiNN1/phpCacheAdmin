<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use JsonException;
use RobiNN\Pca\Dashboards\Metrics;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\PredisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\RedisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis;
use RobiNN\Pca\Template;

readonly class RedisMetrics extends Metrics {
    /**
     * @param array<int, array<string, int|string>> $servers
     */
    public function __construct(
        private Redis|Predis|RedisCluster|PredisCluster $redis,
        Template                                        $template,
        array                                           $servers,
        int                                             $selected,
    ) {
        parent::__construct($template, $servers, $selected);

        $this->updateSchema([
            'commands_stats' => 'TEXT',
        ]);
    }

    protected function dbPrefix(): string {
        return 'redis';
    }

    protected function schema(): string {
        return <<<SQL
        CREATE TABLE IF NOT EXISTS metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp INTEGER NOT NULL,
            commands_per_second INTEGER,
            hit_rate REAL,
            memory_used INTEGER,
            memory_peak INTEGER,
            fragmentation_ratio REAL,
            connections INTEGER,
            commands_stats TEXT
        )
        SQL;
    }

    /**
     * @return array<string, int|float|string>
     *
     * @throws JsonException
     */
    protected function collect(): array {
        $info = $this->redis->getInfo(null, [
            'used_memory',
            'used_memory_peak',
            'mem_fragmentation_ratio',
            'keyspace_hits',
            'keyspace_misses',
            'connected_clients',
            'instantaneous_ops_per_sec',
        ]);

        $keyspace_hits = $info['stats']['keyspace_hits'] ?? 0;
        $keyspace_misses = $info['stats']['keyspace_misses'] ?? 0;
        $total_commands = $keyspace_hits + $keyspace_misses;

        $parsed_commands = $this->redis->parseSectionData('commandstats');
        $command_calls = [];

        foreach ($parsed_commands as $cmd => $details) {
            $name = str_replace('cmdstat_', '', $cmd);
            $command_calls[$name] = (int) ($details['calls'] ?? 0);
        }

        return [
            'timestamp'           => time(),
            'commands_per_second' => $info['stats']['instantaneous_ops_per_sec'] ?? 0,
            'hit_rate'            => $total_commands > 0 ? round(($keyspace_hits / $total_commands) * 100, 2) : 0.0,
            'memory_used'         => $info['memory']['used_memory'] ?? 0,
            'memory_peak'         => $info['memory']['used_memory_peak'] ?? 0,
            'fragmentation_ratio' => $info['memory']['mem_fragmentation_ratio'] ?? 0,
            'connections'         => $info['clients']['connected_clients'] ?? 0,
            'commands_stats'      => json_encode($command_calls, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected function formatRow(array $row): array {
        return [
            'timestamp'           => date('Y-m-d H:i:s', (int) $row['timestamp']),
            'unix_timestamp'      => (int) $row['timestamp'],
            'commands_per_second' => $row['commands_per_second'],
            'hit_rate'            => $row['hit_rate'],
            'memory'              => [
                'used'          => $row['memory_used'],
                'peak'          => $row['memory_peak'],
                'fragmentation' => $row['fragmentation_ratio'],
            ],
            'connections'         => $row['connections'],
            'commands_stats'      => json_decode((string) $row['commands_stats'], true) ?? [],
        ];
    }
}
