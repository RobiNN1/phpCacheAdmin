<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Dashboards\Memcached;

use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class MemcachedHealthTest extends TestCase {
    private MemcachedDashboard $dashboard;

    protected function setUp(): void {
        $this->dashboard = new MemcachedDashboard(new Template());
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, array<string, mixed>>
     */
    private function checks(array $info): array {
        $checks = [];

        foreach ($this->dashboard->getHealthChecks($info) as $check) {
            $checks[$check['name']] = $check;
        }

        return $checks;
    }

    public function testChecksAndTheirOrder(): void {
        $names = array_column($this->dashboard->getHealthChecks([]), 'name');

        $this->assertSame(['Memory usage', 'Hit rate', 'Evictions', 'Connections'], $names);
    }

    public function testMemoryCheck(): void {
        $memory = $this->checks(['bytes' => 10, 'limit_maxbytes' => 100])['Memory usage'];
        $this->assertSame('healthy', $memory['status']);
        $this->assertEqualsWithDelta(10.0, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $memory['suggestion']);

        $memory = $this->checks(['bytes' => 60, 'limit_maxbytes' => 100])['Memory usage'];
        $this->assertSame('warning', $memory['status']);
        $this->assertStringContainsString('-m', (string) $memory['suggestion']);

        $memory = $this->checks(['bytes' => 90, 'limit_maxbytes' => 100])['Memory usage'];
        $this->assertSame('critical', $memory['status']);
        $this->assertEqualsWithDelta(90.0, $memory['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testHitRateCheck(): void {
        $hit_rate = $this->checks([])['Hit rate'];
        $this->assertSame('critical', $hit_rate['status']);
        $this->assertEqualsWithDelta(0.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);

        $hit_rate = $this->checks(['cmd_get' => 100, 'get_hits' => 60])['Hit rate'];
        $this->assertSame('warning', $hit_rate['status']);
        $this->assertEqualsWithDelta(60.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('hit rate can be normal', (string) $hit_rate['suggestion']);

        $hit_rate = $this->checks(['cmd_get' => 100, 'get_hits' => 90])['Hit rate'];
        $this->assertSame('healthy', $hit_rate['status']);
        $this->assertEqualsWithDelta(90.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $hit_rate['suggestion']);
    }

    public function testEvictionsCheck(): void {
        $evictions = $this->checks([])['Evictions'];
        $this->assertSame('healthy', $evictions['status']);
        $this->assertEqualsWithDelta(0.0, $evictions['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $evictions['suggestion']);

        $evictions = $this->checks(['evictions' => 5, 'total_items' => 100])['Evictions'];
        $this->assertSame('warning', $evictions['status']);
        $this->assertEqualsWithDelta(5.0, $evictions['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('-m', (string) $evictions['suggestion']);

        $evictions = $this->checks(['evictions' => 20, 'total_items' => 100])['Evictions'];
        $this->assertSame('critical', $evictions['status']);
        $this->assertEqualsWithDelta(20.0, $evictions['utilization'], PHP_FLOAT_EPSILON);

        $evictions = $this->checks(['evictions' => 5])['Evictions'];
        $this->assertSame('warning', $evictions['status']);
        $this->assertEqualsWithDelta(0.0, $evictions['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testConnectionsCheck(): void {
        $connections = $this->checks(['curr_connections' => 5, 'max_connections' => 100])['Connections'];
        $this->assertSame('healthy', $connections['status']);
        $this->assertEqualsWithDelta(5.0, $connections['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('5 of 100 connections', (string) $connections['detail']);

        $connections = $this->checks(['curr_connections' => 60, 'max_connections' => 100])['Connections'];
        $this->assertSame('warning', $connections['status']);
        $this->assertStringContainsString('-c', (string) $connections['suggestion']);

        $connections = $this->checks(['curr_connections' => 7])['Connections'];
        $this->assertSame('healthy', $connections['status']);
        $this->assertEqualsWithDelta(0.0, $connections['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('7 connections', (string) $connections['detail']);

        $connections = $this->checks(['curr_connections' => 1, 'max_connections' => 100, 'rejected_connections' => 3])['Connections'];
        $this->assertSame('critical', $connections['status']);
        $this->assertEqualsWithDelta(100.0, $connections['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('rejected', (string) $connections['suggestion']);
    }
}
