<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Memcached;

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

        $this->assertSame(['Memory usage', 'Hit rate', 'Evictions', 'Evicted before read', 'Connections'], $names);
    }

    public function testMemoryCheckHealthy(): void {
        $memory = $this->checks(['bytes' => 10, 'limit_maxbytes' => 100])['Memory usage'];

        $this->assertSame('healthy', $memory['status']);
        $this->assertEqualsWithDelta(10.0, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $memory['suggestion']);
    }

    public function testMemoryCheckWarning(): void {
        $memory = $this->checks(['bytes' => 60, 'limit_maxbytes' => 100])['Memory usage'];

        $this->assertSame('warning', $memory['status']);
        $this->assertStringContainsString('-m', (string) $memory['suggestion']);
    }

    public function testMemoryCheckCritical(): void {
        $memory = $this->checks(['bytes' => 90, 'limit_maxbytes' => 100])['Memory usage'];

        $this->assertSame('critical', $memory['status']);
        $this->assertEqualsWithDelta(90.0, $memory['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testHitRateCheckWithoutStats(): void {
        $hit_rate = $this->checks([])['Hit rate'];

        $this->assertSame('info', $hit_rate['status']);
        $this->assertEqualsWithDelta(0.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('Too few gets', (string) $hit_rate['suggestion']);
    }

    public function testHitRateCheckWithTooFewGets(): void {
        $hit_rate = $this->checks(['cmd_get' => 100, 'get_hits' => 10, 'uptime' => 86400])['Hit rate'];

        $this->assertSame('info', $hit_rate['status']);
        $this->assertEqualsWithDelta(10.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testHitRateCheckRightAfterRestart(): void {
        $hit_rate = $this->checks(['cmd_get' => 10000, 'get_hits' => 1000, 'uptime' => 600])['Hit rate'];

        $this->assertSame('info', $hit_rate['status']);
        $this->assertStringContainsString('warming up', (string) $hit_rate['suggestion']);
    }

    public function testHitRateCheckWarning(): void {
        $hit_rate = $this->checks(['cmd_get' => 10000, 'get_hits' => 6000, 'uptime' => 86400])['Hit rate'];

        $this->assertSame('warning', $hit_rate['status']);
        $this->assertEqualsWithDelta(60.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('TTLs', (string) $hit_rate['suggestion']);
    }

    public function testHitRateCheckHealthy(): void {
        $hit_rate = $this->checks(['cmd_get' => 10000, 'get_hits' => 9000, 'uptime' => 86400])['Hit rate'];

        $this->assertSame('healthy', $hit_rate['status']);
        $this->assertEqualsWithDelta(90.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $hit_rate['suggestion']);
    }

    public function testEvictionsCheckHealthy(): void {
        $evictions = $this->checks([])['Evictions'];

        $this->assertSame('healthy', $evictions['status']);
        $this->assertEqualsWithDelta(0.0, $evictions['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $evictions['suggestion']);
    }

    public function testEvictionsCheckWarning(): void {
        $evictions = $this->checks(['evictions' => 5, 'total_items' => 100])['Evictions'];

        $this->assertSame('warning', $evictions['status']);
        $this->assertEqualsWithDelta(5.0, $evictions['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('-m', (string) $evictions['suggestion']);
    }

    public function testEvictionsCheckCritical(): void {
        $evictions = $this->checks(['evictions' => 20, 'total_items' => 100])['Evictions'];

        $this->assertSame('critical', $evictions['status']);
        $this->assertEqualsWithDelta(20.0, $evictions['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testEvictionsCheckWithoutTotalItems(): void {
        $evictions = $this->checks(['evictions' => 5])['Evictions'];

        $this->assertSame('warning', $evictions['status']);
        $this->assertEqualsWithDelta(0.0, $evictions['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testConnectionsCheckHealthy(): void {
        $connections = $this->checks(['curr_connections' => 5, 'max_connections' => 100])['Connections'];

        $this->assertSame('healthy', $connections['status']);
        $this->assertEqualsWithDelta(5.0, $connections['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('5 of 100 connections', (string) $connections['detail']);
    }

    public function testConnectionsCheckWarning(): void {
        $connections = $this->checks(['curr_connections' => 60, 'max_connections' => 100])['Connections'];

        $this->assertSame('warning', $connections['status']);
        $this->assertStringContainsString('-c', (string) $connections['suggestion']);
    }

    public function testConnectionsCheckWithoutLimit(): void {
        $connections = $this->checks(['curr_connections' => 7])['Connections'];

        $this->assertSame('healthy', $connections['status']);
        $this->assertEqualsWithDelta(0.0, $connections['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('7 connections', (string) $connections['detail']);
    }

    public function testConnectionsCheckWithRejectedConnections(): void {
        $connections = $this->checks(['curr_connections' => 1, 'max_connections' => 100, 'rejected_connections' => 3])['Connections'];

        $this->assertSame('critical', $connections['status']);
        $this->assertEqualsWithDelta(100.0, $connections['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('rejected', (string) $connections['suggestion']);
    }

    public function testUnreadEvictionsCheckHealthy(): void {
        $check = $this->checks(['evictions' => 10, 'evicted_unfetched' => 0])['Evicted before read'];

        $this->assertSame('healthy', $check['status']);
        $this->assertSame('', $check['suggestion']);
    }

    public function testUnreadEvictionsCheckWarning(): void {
        $check = $this->checks(['evictions' => 10, 'evicted_unfetched' => 4])['Evicted before read'];

        $this->assertSame('warning', $check['status']);
        $this->assertEqualsWithDelta(40.0, $check['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('-m', (string) $check['suggestion']);
    }

    public function testConnectionsCheckWithDisabledListener(): void {
        $connections = $this->checks(['curr_connections' => 1, 'max_connections' => 100, 'listen_disabled_num' => 2])['Connections'];

        $this->assertSame('critical', $connections['status']);
        $this->assertStringContainsString('stopped accepting', (string) $connections['suggestion']);
    }
}
