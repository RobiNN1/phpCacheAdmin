<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use JsonException;
use PDO;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\PredisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\RedisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

readonly class RedisMetrics {
    private PDO $pdo;

    /**
     * @param array<int, array<string, int|string>> $servers
     */
    public function __construct(
        private Redis|Predis|RedisCluster|PredisCluster $redis,
        private Template                                $template,
        array                                           $servers,
        int                                             $selected,
    ) {
        $server_name = Helpers::getServerTitle($servers[$selected]);
        $hash = md5($server_name.Config::get('hash', 'pca'));
        $db = __DIR__.'/../../../tmp/redis_metrics_'.$hash.'.db';

        $this->pdo = new PDO('sqlite:'.$db);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp INTEGER NOT NULL,
            commands_per_second INTEGER, hit_rate REAL, memory_used INTEGER, memory_peak INTEGER,
            fragmentation_ratio REAL, connections INTEGER
        )
        SQL;

        $this->pdo->exec($schema);
    }

    public function collectAndRespond(): string {
        $info = $this->redis->getInfo(null, [
                'used_memory',
                'used_memory_peak',
                'mem_fragmentation_ratio',
                'keyspace_hits',
                'keyspace_misses',
                'connected_clients',
                'instantaneous_ops_per_sec',
            ]);

        $metrics = $this->calculateMetrics($info);
        $this->insertMetrics($metrics);
        $recent_data = $this->fetchRecentMetrics();
        $formatted_data = $this->formatDataForResponse($recent_data);

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

        try {
            return json_encode($formatted_data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            return Helpers::alert($this->template, $e->getMessage(), 'error');
        }
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, int|float>
     */
    private function calculateMetrics(array $info): array {
        $keyspace_hits = $info['stats']['keyspace_hits'] ?? 0;
        $keyspace_misses = $info['stats']['keyspace_misses'] ?? 0;
        $total_commands = $keyspace_hits + $keyspace_misses;

        return [
            'timestamp'           => time(),
            'commands_per_second' => $info['stats']['instantaneous_ops_per_sec'] ?? 0,
            'hit_rate'            => $total_commands > 0 ? round(($keyspace_hits / $total_commands) * 100, 2) : 0.0,
            'memory_used'         => $info['memory']['used_memory'] ?? 0,
            'memory_peak'         => $info['memory']['used_memory_peak'] ?? 0,
            'fragmentation_ratio' => $info['memory']['mem_fragmentation_ratio'] ?? 0,
            'connections'         => $info['clients']['connected_clients'] ?? 0,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function insertMetrics(array $metrics): void {
        $columns = implode(', ', array_keys($metrics));
        $placeholders = rtrim(str_repeat('?, ', count($metrics)), ', ');
        $sql = sprintf('INSERT INTO metrics (%s) VALUES (%s)', $columns, $placeholders);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($metrics));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentMetrics(): array {
        $max_data_points_to_return = Http::post('points', Config::get('metricstab', 1440));

        $stmt = $this->pdo->prepare('SELECT * FROM metrics ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $max_data_points_to_return, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_reverse($results);
    }

    /**
     * @param array<int, array<string, mixed>> $db_rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatDataForResponse(array $db_rows): array {
        $formatted_results = [];

        foreach ($db_rows as $row) {
            $formatted_results[] = [
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
            ];
        }

        return $formatted_results;
    }
}
