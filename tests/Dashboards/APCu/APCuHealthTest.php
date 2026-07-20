<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\APCu;

use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class APCuHealthTest extends TestCase {
    private APCuDashboard $dashboard;

    public static function setUpBeforeClass(): void {
        if (ini_get('apc.enable_cli') !== '1') {
            self::markTestSkipped('APC CLI is not enabled. Skipping tests.');
        }
    }

    protected function setUp(): void {
        $this->dashboard = new APCuDashboard(new Template());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function info(array $overrides = []): array {
        return $overrides + [
                'num_hits'   => 9500,
                'num_misses' => 500,
                'expunges'   => 0,
                'start_time' => time() - 3600,
            ];
    }

    /**
     * One 8 MB segment, half of it free in a single block.
     *
     * @param array<int, array<int, array<string, int>>> $block_lists
     *
     * @return array<string, mixed>
     */
    private function sma(array $block_lists = [[['size' => 4_194_304, 'offset' => 0]]]): array {
        return [
            'num_seg'     => 1,
            'seg_size'    => 8_388_608,
            'avail_mem'   => 4_194_304,
            'block_lists' => $block_lists,
        ];
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $sma
     *
     * @return array<string, array<string, mixed>>
     */
    private function checks(array $info = [], ?array $sma = null): array {
        $checks = [];

        foreach ($this->dashboard->getHealthChecks($this->info($info), $sma ?? $this->sma()) as $check) {
            $checks[$check['name']] = $check;
        }

        return $checks;
    }

    public function testChecksAndTheirOrder(): void {
        $names = array_column($this->dashboard->getHealthChecks($this->info(), $this->sma()), 'name');

        $this->assertSame(['Memory usage', 'Hit rate', 'Fragmentation', 'Cache full count'], $names);
    }

    public function testMemoryCheckHealthy(): void {
        $memory = $this->checks()['Memory usage'];

        $this->assertSame('healthy', $memory['status']);
        $this->assertEqualsWithDelta(50.0, $memory['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testMemoryCheckCritical(): void {
        $memory = $this->checks([], ['num_seg' => 1, 'seg_size' => 8_388_608, 'avail_mem' => 838_860, 'block_lists' => []])['Memory usage'];

        $this->assertSame('critical', $memory['status']);
        $this->assertStringContainsString('apc.shm_size', (string) $memory['suggestion']);
    }

    public function testHitRateCheckHealthy(): void {
        $hit_rate = $this->checks()['Hit rate'];

        $this->assertSame('healthy', $hit_rate['status']);
        $this->assertEqualsWithDelta(95.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testHitRateCheckCritical(): void {
        $hit_rate = $this->checks(['num_hits' => 100, 'num_misses' => 900])['Hit rate'];

        $this->assertSame('critical', $hit_rate['status']);
        $this->assertEqualsWithDelta(10.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testHitRateCheckWithoutLookups(): void {
        $hit_rate = $this->checks(['num_hits' => 0, 'num_misses' => 0])['Hit rate'];

        $this->assertEqualsWithDelta(0.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testFragmentationOfASingleFreeBlock(): void {
        $fragmentation = $this->checks()['Fragmentation'];

        $this->assertSame('healthy', $fragmentation['status']);
        $this->assertEqualsWithDelta(0.0, $fragmentation['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testFragmentationOfEvenlySplitBlocks(): void {
        $blocks = [[['size' => 1000, 'offset' => 0], ['size' => 1000, 'offset' => 2000], ['size' => 1000, 'offset' => 4000], ['size' => 1000, 'offset' => 6000]]];

        $fragmentation = $this->checks([], $this->sma($blocks))['Fragmentation'];

        $this->assertSame('critical', $fragmentation['status']);
        $this->assertEqualsWithDelta(75.0, $fragmentation['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testFragmentationCountsBlocksAcrossSegments(): void {
        $blocks = [[['size' => 800, 'offset' => 0]], [['size' => 200, 'offset' => 0]]];

        $fragmentation = $this->dashboard->fragmentation($this->sma($blocks));

        $this->assertSame(2, $fragmentation['blocks']);
        $this->assertSame(1000, $fragmentation['free']);
        $this->assertSame(800, $fragmentation['largest']);
        $this->assertEqualsWithDelta(20.0, $fragmentation['percentage'], PHP_FLOAT_EPSILON);
    }

    public function testFragmentationWithoutFreeMemory(): void {
        $fragmentation = $this->dashboard->fragmentation($this->sma([]));

        $this->assertSame(0, $fragmentation['blocks']);
        $this->assertEqualsWithDelta(0.0, $fragmentation['percentage'], PHP_FLOAT_EPSILON);
    }

    public function testExpungesCheckHealthy(): void {
        $expunges = $this->checks()['Cache full count'];

        $this->assertSame('healthy', $expunges['status']);
        $this->assertSame('', $expunges['suggestion']);
    }

    public function testExpungesCheckCritical(): void {
        $expunges = $this->checks(['expunges' => 5, 'start_time' => time() - 3600])['Cache full count'];

        $this->assertSame('critical', $expunges['status']);
        $this->assertStringContainsString('apc.shm_size', (string) $expunges['suggestion']);
    }

    public function testExpungesCheckWarnsBelowOnePerHour(): void {
        $expunges = $this->checks(['expunges' => 1, 'start_time' => time() - (3600 * 5)])['Cache full count'];

        $this->assertSame('warning', $expunges['status']);
    }
}
