<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards;

use Exception;
use JsonException;
use PDO;
use RobiNN\Pca\Config;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use RuntimeException;

abstract readonly class Metrics {
    protected PDO $pdo;

    /**
     * @param array<int, array<string, int|string>> $servers
     */
    public function __construct(protected Template $template, array $servers, int $selected) {
        $server_name = Helpers::getServerTitle($servers[$selected]);
        $hash = md5($server_name.Config::get('hash', 'pca'));
        $dir = Config::get('metricsdir', __DIR__.'/../../tmp/metrics');
        $db = $dir.'/'.$this->dbPrefix().'_metrics_'.$hash.'.db';

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        $this->pdo = new PDO('sqlite:'.$db);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec($this->schema());
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS metrics_timestamp ON metrics (timestamp)');
    }

    /**
     * Prefix for the database file name, e.g. "redis".
     */
    abstract protected function dbPrefix(): string;

    /**
     * "CREATE TABLE" statement for the metrics table.
     */
    abstract protected function schema(): string;

    /**
     * Collect the current values as one row for the metrics table.
     *
     * @return array<string, int|float|string>
     */
    abstract protected function collect(): array;

    /**
     * Format a database row for the chart response.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    abstract protected function formatRow(array $row): array;

    public function collectAndRespond(): string {
        if ($this->shouldInsert()) {
            try {
                $this->insertMetrics($this->collect());
            } catch (Exception $e) {
                return Helpers::alert($this->template, $e->getMessage(), 'error');
            }
        }

        $formatted_data = array_map($this->formatRow(...), $this->fetchRecentMetrics());

        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, must-revalidate');
        }

        try {
            return json_encode($formatted_data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            return Helpers::alert($this->template, $e->getMessage(), 'error');
        }
    }

    /**
     * Add columns that did not exist in older database files.
     *
     * @param array<string, string> $new_columns
     */
    protected function updateSchema(array $new_columns): void {
        try {
            $statement = $this->pdo->query('PRAGMA table_info(metrics)');
            $existing_columns = $statement->fetchAll(PDO::FETCH_COLUMN, 1);

            foreach ($new_columns as $column_name => $type) {
                if (!in_array($column_name, $existing_columns, true)) {
                    $this->pdo->exec(sprintf('ALTER TABLE metrics ADD COLUMN %s %s', $column_name, $type));
                }
            }
        } catch (Exception) {
        }
    }

    /**
     * Prevents duplicate samples when multiple browser tabs are open or a cronjob runs alongside.
     */
    protected function shouldInsert(): bool {
        $last = $this->pdo->query('SELECT MAX(timestamp) FROM metrics')->fetchColumn();

        return $last === null || (time() - (int) $last) >= (int) ceil(((int) Config::get('metricsrefresh', 60)) / 2);
    }

    /**
     * @param array<string, mixed> $metrics
     */
    protected function insertMetrics(array $metrics): void {
        $columns = implode(', ', array_keys($metrics));
        $placeholders = rtrim(str_repeat('?, ', count($metrics)), ', ');
        $sql = sprintf('INSERT INTO metrics (%s) VALUES (%s)', $columns, $placeholders);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($metrics));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRecentMetrics(): array {
        $filter = Http::post('filter', Config::get('metricstab', '1d'));

        $seconds = match ($filter) {
            '1h' => 3600,
            '1w' => 604800,
            '1m' => 2592000,
            default => 86400,
        };

        $time_ago = time() - $seconds;

        $bucket = max(1, intdiv($seconds, 1440));

        $stmt = $this->pdo->prepare('SELECT *, MAX(id) FROM metrics WHERE timestamp >= :time_ago GROUP BY timestamp / :bucket ORDER BY timestamp');
        $stmt->bindValue(':time_ago', $time_ago, PDO::PARAM_INT);
        $stmt->bindValue(':bucket', $bucket, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
