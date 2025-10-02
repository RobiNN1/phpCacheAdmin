<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use JsonException;
use PDO;
use RobiNN\Pca\Config;
use RobiNN\Pca\Http;

class MemcachedMetrics {
    private PDO $pdo;

    private const RATE_COMMANDS = ['get', 'set', 'delete', 'incr', 'decr', 'cas', 'touch', 'flush'];
    private const HIT_RATE_COMMANDS = ['get', 'delete', 'incr', 'decr', 'cas', 'touch'];

    public function __construct(private readonly PHPMem $memcached) {
        $hash = md5(Config::get('hash', 'pca')); // This isn't really safe, but it's better than nothing
        $db = __DIR__.'/../../../tmp/memcached_metrics'.$hash.'.db';

        $this->pdo = new PDO('sqlite:'.$db);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp INTEGER NOT NULL,
            memory_used INTEGER, memory_limit INTEGER, stored_items INTEGER, connections INTEGER,
            new_connection_rate REAL, eviction_rate REAL, expired_rate REAL,
            hit_rate_overall REAL, hit_rate_get REAL, hit_rate_delete REAL, hit_rate_incr REAL, hit_rate_decr REAL, hit_rate_cas REAL, hit_rate_touch REAL,
            request_rate_overall REAL, request_rate_get REAL, request_rate_set REAL, request_rate_delete REAL, request_rate_incr REAL, request_rate_decr REAL, request_rate_cas REAL, request_rate_touch REAL, request_rate_flush REAL,
            traffic_received_rate REAL, traffic_sent_rate REAL,
            cumulative_total_connections INTEGER, cumulative_evictions INTEGER, cumulative_expired_unfetched INTEGER,
            cumulative_bytes_read INTEGER, cumulative_bytes_written INTEGER,
            cumulative_cmd_get INTEGER, cumulative_cmd_set INTEGER, cumulative_cmd_delete INTEGER, cumulative_cmd_incr INTEGER, cumulative_cmd_decr INTEGER, cumulative_cmd_cas INTEGER, cumulative_cmd_touch INTEGER, cumulative_cmd_flush INTEGER
        )
        SQL;

        $this->pdo->exec($schema);
    }

    /**
     * @throws MemcachedException
     */
    public function collectAndRespond(): string {
        $stats = $this->memcached->getServerStats();

        if (empty($stats)) {
            throw new MemcachedException('Failed to retrieve Memcached stats.');
        }

        $metrics = $this->calculateMetrics($stats);
        $this->insertMetrics($metrics);
        $recent_data = $this->fetchRecentMetrics();
        $formatted_data = $this->formatDataForResponse($recent_data);

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

        try {
            return json_encode($formatted_data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $stats Raw stats from Memcached.
     *
     * @return array<string, mixed>
     */
    private function calculateMetrics(array $stats): array {
        /** @var array<string, mixed>|false $last_point */
        $last_point = $this->pdo->query('SELECT * FROM metrics ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        $time_diff = ($last_point && isset($last_point['timestamp'])) ? time() - (int) $last_point['timestamp'] : 60;
        $time_diff = max($time_diff, 1);

        $calculate_rate = static function (int|float $current_val, ?int $last_val) use ($time_diff): float {
            if ($last_val === null || $current_val < $last_val) {
                return 0.0;
            }

            return round(($current_val - $last_val) / $time_diff, 2);
        };

        $calculate_hit_rate = static function (array $stats, string $cmd): float {
            $hits = $stats[$cmd.'_hits'] ?? 0;
            $misses = $stats[$cmd.'_misses'] ?? 0;
            $total = $hits + $misses;

            return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
        };

        $command_rates = [];

        foreach (self::RATE_COMMANDS as $cmd) {
            $command_rates['request_rate_'.$cmd] = $calculate_rate(
                $stats['cmd_'.$cmd] ?? 0,
                $last_point['cumulative_cmd_'.$cmd] ?? null
            );
        }

        $command_rates['request_rate_overall'] = array_sum($command_rates);

        $hit_rates = [];
        $total_hits = $total_misses = 0;

        foreach (self::HIT_RATE_COMMANDS as $cmd) {
            $hit_rates['hit_rate_'.$cmd] = $calculate_hit_rate($stats, $cmd);
            $total_hits += $stats[$cmd.'_hits'] ?? 0;
            $total_misses += $stats[$cmd.'_misses'] ?? 0;
        }

        $hit_rates['hit_rate_overall'] = ($total_hits + $total_misses > 0) ? round(($total_hits / ($total_hits + $total_misses)) * 100, 2) : 0.0;

        $metrics_to_insert = array_merge($hit_rates, $command_rates, [
            'timestamp'                    => time(),
            'memory_used'                  => $stats['bytes'] ?? 0,
            'memory_limit'                 => $stats['limit_maxbytes'] ?? 0,
            'stored_items'                 => $stats['curr_items'] ?? 0,
            'connections'                  => $stats['curr_connections'] ?? 0,
            'new_connection_rate'          => $calculate_rate($stats['total_connections'] ?? 0, $last_point['cumulative_total_connections'] ?? null),
            'eviction_rate'                => $calculate_rate($stats['evictions'] ?? 0, $last_point['cumulative_evictions'] ?? null),
            'expired_rate'                 => $calculate_rate($stats['expired_unfetched'] ?? 0, $last_point['cumulative_expired_unfetched'] ?? null),
            'traffic_received_rate'        => $calculate_rate($stats['bytes_read'] ?? 0, $last_point['cumulative_bytes_read'] ?? null),
            'traffic_sent_rate'            => $calculate_rate($stats['bytes_written'] ?? 0, $last_point['cumulative_bytes_written'] ?? null),
            'cumulative_total_connections' => $stats['total_connections'] ?? 0,
            'cumulative_evictions'         => $stats['evictions'] ?? 0,
            'cumulative_expired_unfetched' => $stats['expired_unfetched'] ?? 0,
            'cumulative_bytes_read'        => $stats['bytes_read'] ?? 0,
            'cumulative_bytes_written'     => $stats['bytes_written'] ?? 0,
        ]);

        foreach (self::RATE_COMMANDS as $cmd) {
            $metrics_to_insert['cumulative_cmd_'.$cmd] = $stats['cmd_'.$cmd] ?? 0;
        }

        return $metrics_to_insert;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function insertMetrics(array $metrics): void {
        $columns = implode(', ', array_keys($metrics));
        $placeholders = rtrim(str_repeat('?, ', count($metrics)), ', ');
        $sql = "INSERT INTO metrics ($columns) VALUES ($placeholders)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($metrics));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentMetrics(): array {
        $max_data_points_to_return = Http::get('points', Config::get('metricstab', 1440));

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
                'hit_rates'           => [
                    'overall' => $row['hit_rate_overall'], 'get' => $row['hit_rate_get'], 'delete' => $row['hit_rate_delete'],
                    'incr'    => $row['hit_rate_incr'], 'decr' => $row['hit_rate_decr'], 'cas' => $row['hit_rate_cas'], 'touch' => $row['hit_rate_touch'],
                ],
                'request_rates'       => [
                    'overall' => $row['request_rate_overall'], 'get' => $row['request_rate_get'], 'set' => $row['request_rate_set'],
                    'delete'  => $row['request_rate_delete'], 'incr' => $row['request_rate_incr'], 'decr' => $row['request_rate_decr'],
                    'cas'     => $row['request_rate_cas'], 'touch' => $row['request_rate_touch'], 'flush' => $row['request_rate_flush'],
                ],
                'traffic'             => [
                    'received_rate' => $row['traffic_received_rate'],
                    'sent_rate'     => $row['traffic_sent_rate'],
                ],
                'memory_used'         => $row['memory_used'],
                'memory_limit'        => $row['memory_limit'],
                'stored_items'        => $row['stored_items'],
                'eviction_rate'       => $row['eviction_rate'],
                'expired_rate'        => $row['expired_rate'],
                'connections'         => $row['connections'],
                'new_connection_rate' => $row['new_connection_rate'],
            ];
        }

        return $formatted_results;
    }
}
