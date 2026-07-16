<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use Iterator;
use JsonException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Dashboards\Metrics;

readonly class DummyMetrics extends Metrics {
    protected function schema(): string {
        return 'CREATE TABLE IF NOT EXISTS metrics (id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp INTEGER NOT NULL, value INTEGER)';
    }

    protected function collect(): array {
        return ['timestamp' => time(), 'value' => 42];
    }

    protected function formatRow(array $row): array {
        return ['unix_timestamp' => (int) $row['timestamp'], 'value' => (int) $row['value']];
    }

    public function db(): PDO {
        return $this->pdo;
    }

    /**
     * @param array<string, string> $columns
     */
    public function migrate(array $columns): void {
        $this->updateSchema($columns);
    }
}

final class MetricsTest extends TestCase {
    private DummyMetrics $metrics;

    private string $db_file;

    protected function setUp(): void {
        $this->metrics = new DummyMetrics([['name' => 'pu-metrics-'.uniqid('', true)]], 0);

        $database = $this->metrics->db()->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
        $this->db_file = (string) $database['file'];
    }

    protected function tearDown(): void {
        unset($_POST['filter']);
        @unlink($this->db_file);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws JsonException
     */
    private function collect(): array {
        $data = json_decode($this->metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @throws JsonException
     */
    public function testCollectAndRespond(): void {
        $data = $this->collect();

        $this->assertCount(1, $data);
        $this->assertSame(42, $data[0]['value']);
        $this->assertEqualsWithDelta(time(), $data[0]['unix_timestamp'], 5);
    }

    /**
     * @throws JsonException
     */
    public function testDuplicateCollectionsAreSkipped(): void {
        $this->collect();
        $data = $this->collect();

        $this->assertCount(1, $data);

        $this->metrics->db()->exec('UPDATE metrics SET timestamp = timestamp - 60');
        $data = $this->collect();

        $this->assertCount(2, $data);
    }

    /**
     * @throws JsonException
     */
    public function testLongRangesAreDownsampled(): void {
        $stmt = $this->metrics->db()->prepare('INSERT INTO metrics (timestamp, value) VALUES (?, 42)');
        $this->metrics->db()->beginTransaction();

        for ($t = time() - 2 * 86400; $t < time(); $t += 60) {
            $stmt->execute([$t]);
        }

        $this->metrics->db()->commit();

        $_POST['filter'] = '1w';
        $data = $this->collect();

        $this->assertGreaterThan(390, count($data));
        $this->assertLessThan(430, count($data));

        $timestamps = array_column($data, 'unix_timestamp');
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    /**
     * @return Iterator<string, array{string, int}>
     */
    public static function filterProvider(): Iterator {
        yield '1h' => ['1h', 3600];
        yield '1d' => ['1d', 86400];
        yield '1w' => ['1w', 604800];
        yield '1m' => ['1m', 2592000];
        yield 'unknown falls back to 1d' => ['nonsense', 86400];
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('filterProvider')]
    public function testFilterSelectsItsTimeWindow(string $filter, int $window): void {
        $stmt = $this->metrics->db()->prepare('INSERT INTO metrics (timestamp, value) VALUES (?, 42)');
        $inside = time() - ($window - 100);
        $outside = time() - ($window + 100);
        $stmt->execute([$inside]);
        $stmt->execute([$outside]);

        $_POST['filter'] = $filter;
        $timestamps = array_column($this->collect(), 'unix_timestamp');

        $this->assertContains($inside, $timestamps);
        $this->assertNotContains($outside, $timestamps);
    }

    public function testUpdateSchemaAddsMissingColumns(): void {
        $columns = fn (): array => $this->metrics->db()->query('PRAGMA table_info(metrics)')->fetchAll(PDO::FETCH_COLUMN, 1);

        $this->assertNotContains('extra_col', $columns());

        $this->metrics->migrate(['extra_col' => 'INTEGER']);
        $this->assertContains('extra_col', $columns());

        $this->metrics->migrate(['extra_col' => 'INTEGER']);
        $this->assertCount(1, array_filter($columns(), static fn (string $c): bool => $c === 'extra_col'));
    }
}
