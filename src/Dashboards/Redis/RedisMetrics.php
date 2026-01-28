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
        $dir = Config::get('metricsdir', __DIR__.'/../../../tmp/metrics');
        $db = $dir.'/redis_metrics_'.$hash.'.db';

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        $this->pdo = new PDO('sqlite:'.$db);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = <<<SQL
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

        $this->pdo->exec($schema);

        $this->updateSchema([
            'commands_stats' => 'TEXT',
        ]);
    }

    /**
     * @param array<string, string> $new_columns
     */
    private function updateSchema(array $new_columns): void {
        try {
            $statement = $this->pdo->query('PRAGMA table_info(metrics)');
            $existing_columns = $statement->fetchAll(PDO::FETCH_COLUMN, 1);

            foreach ($new_columns as $column_name => $type) {
                if (!in_array($column_name, $existing_columns, true)) {
                    $this->pdo->exec(sprintf('ALTER TABLE metrics ADD COLUMN %s %s', $column_name, $type));
                }
            }
        } catch (\Exception) {
        }
    }

    /**
     * @throws JsonException
     */
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
     * @return array<string, int|float|string>
     *
     * @throws JsonException
     */
    private function calculateMetrics(array $info): array {
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

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, array<string, mixed>> $db_rows
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws JsonException
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
                'commands_stats'      => json_decode((string) $row['commands_stats'], true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return $formatted_results;
    }
}
