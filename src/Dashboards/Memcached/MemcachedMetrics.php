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
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

readonly class MemcachedMetrics {
    private PDO $pdo;

    private const RATE_COMMANDS = ['get', 'set', 'delete', 'incr', 'decr', 'cas', 'touch', 'flush'];

    private const HIT_RATE_COMMANDS = ['get', 'delete', 'incr', 'decr', 'cas', 'touch'];

    /**
     * @param array<int, array<string, int|string>> $servers
     */
    public function __construct(
        private PHPMem   $memcached,
        private Template $template,
        array            $servers,
        int              $selected
    ) {
        $server_name = Helpers::getServerTitle($servers[$selected]);
        $hash = md5($server_name.Config::get('hash', 'pca'));
        $dir = Config::get('metricsdir', __DIR__.'/../../../tmp/metrics');
        $db = $dir.'/memcached_metrics_'.$hash.'.db';

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

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

    public function collectAndRespond(): string {
        try {
            $stats = $this->memcached->getServerStats();
        } catch (MemcachedException) {
            return Helpers::alert($this->template, 'Failed to retrieve Memcached stats.', 'error');
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
            return Helpers::alert($this->template, $e->getMessage(), 'error');
        }
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array<string, int|float>
     */
    private function calculateMetrics(array $stats): array {
        $last_point = $this->getLastMetricsPoint();
        $time_diff = $this->calculateTimeDifference($last_point);

        $command_rates = $this->calculateCommandRates($stats, $last_point, $time_diff);
        $hit_rates = $this->calculateHitRates($stats);
        $core_metrics = $this->calculateCoreMetrics($stats, $last_point, $time_diff);
        $cumulative_metrics = $this->cumulativeMetrics($stats);

        return array_merge(['timestamp' => time()], $command_rates, $hit_rates, $core_metrics, $cumulative_metrics);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLastMetricsPoint(): ?array {
        $last_point = $this->pdo->query('SELECT * FROM metrics ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        return $last_point === false ? null : $last_point;
    }

    /**
     * @param array<string, mixed>|null $last_point
     */
    private function calculateTimeDifference(?array $last_point): int {
        $last_timestamp = $last_point['timestamp'] ?? (time() - 60);
        $time_diff = time() - (int) $last_timestamp;

        return max($time_diff, 1);
    }

    private function calculateRate(int|float $current_val, ?int $last_val, int $time_diff): float {
        if ($last_val === null || $current_val < $last_val) {
            return 0.0;
        }

        return round(($current_val - $last_val) / $time_diff, 2);
    }

    /**
     * @param array<string, mixed>      $stats
     * @param array<string, mixed>|null $last_point
     *
     * @return array<string, float>
     */
    private function calculateCommandRates(array $stats, ?array $last_point, int $time_diff): array {
        $command_rates = [];

        foreach (self::RATE_COMMANDS as $cmd) {
            $current_val = $stats['cmd_'.$cmd] ?? 0;
            $last_val = $last_point['cumulative_cmd_'.$cmd] ?? null;
            $command_rates['request_rate_'.$cmd] = $this->calculateRate($current_val, $last_val, $time_diff);
        }

        $command_rates['request_rate_overall'] = array_sum($command_rates);

        return $command_rates;
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array<string, float>
     */
    private function calculateHitRates(array $stats): array {
        $hit_rates = [];
        $total_hits = 0;
        $total_misses = 0;

        foreach (self::HIT_RATE_COMMANDS as $cmd) {
            $hits = $stats[$cmd.'_hits'] ?? 0;
            $misses = $stats[$cmd.'_misses'] ?? 0;
            $total = $hits + $misses;

            $hit_rates['hit_rate_'.$cmd] = ($total > 0) ? round(($hits / $total) * 100, 2) : 0.0;
            $total_hits += $hits;
            $total_misses += $misses;
        }

        $overall_total = $total_hits + $total_misses;
        $hit_rates['hit_rate_overall'] = ($overall_total > 0) ? round(($total_hits / $overall_total) * 100, 2) : 0.0;

        return $hit_rates;
    }

    /**
     * @param array<string, mixed>      $stats
     * @param array<string, mixed>|null $last_point
     *
     * @return array<string, int|float>
     */
    private function calculateCoreMetrics(array $stats, ?array $last_point, int $time_diff): array {
        return [
            'memory_used'           => $stats['bytes'] ?? 0,
            'memory_limit'          => $stats['limit_maxbytes'] ?? 0,
            'stored_items'          => $stats['curr_items'] ?? 0,
            'connections'           => $stats['curr_connections'] ?? 0,
            'new_connection_rate'   => $this->calculateRate(
                $stats['total_connections'] ?? 0,
                $last_point['cumulative_total_connections'] ?? null,
                $time_diff),
            'eviction_rate'         => $this->calculateRate(
                $stats['evictions'] ?? 0,
                $last_point['cumulative_evictions'] ?? null,
                $time_diff
            ),
            'expired_rate'          => $this->calculateRate(
                $stats['expired_unfetched'] ?? 0,
                $last_point['cumulative_expired_unfetched'] ?? null,
                $time_diff
            ),
            'traffic_received_rate' => $this->calculateRate(
                $stats['bytes_read'] ?? 0,
                $last_point['cumulative_bytes_read'] ?? null,
                $time_diff
            ),
            'traffic_sent_rate'     => $this->calculateRate(
                $stats['bytes_written'] ?? 0,
                $last_point['cumulative_bytes_written'] ?? null,
                $time_diff
            ),
        ];
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array<string, int>
     */
    private function cumulativeMetrics(array $stats): array {
        $cumulative_metrics = [
            'cumulative_total_connections' => $stats['total_connections'] ?? 0,
            'cumulative_evictions'         => $stats['evictions'] ?? 0,
            'cumulative_expired_unfetched' => $stats['expired_unfetched'] ?? 0,
            'cumulative_bytes_read'        => $stats['bytes_read'] ?? 0,
            'cumulative_bytes_written'     => $stats['bytes_written'] ?? 0,
        ];

        foreach (self::RATE_COMMANDS as $cmd) {
            $cumulative_metrics['cumulative_cmd_'.$cmd] = $stats['cmd_'.$cmd] ?? 0;
        }

        return $cumulative_metrics;
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
