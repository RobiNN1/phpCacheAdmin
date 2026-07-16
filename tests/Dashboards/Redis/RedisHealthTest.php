<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class RedisHealthTest extends TestCase {
    private RedisDashboard $dashboard;

    protected function setUp(): void {
        $this->dashboard = new RedisDashboard(new Template());
    }

    /**
     * @param array<string, array<string, mixed>> $info
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

        $this->assertSame(['Memory usage', 'Hit rate', 'Evicted keys', 'Clients'], $names);
    }

    public function testMemoryCheck(): void {
        $memory = $this->checks(['memory' => ['used_memory' => 100]])['Memory usage'];
        $this->assertSame('info', $memory['status']);
        $this->assertStringContainsString('no memory limit set', (string) $memory['detail']);

        $memory = $this->checks(['memory' => ['used_memory' => 10, 'maxmemory' => 100, 'maxmemory_policy' => 'allkeys-lru']])['Memory usage'];
        $this->assertSame('healthy', $memory['status']);
        $this->assertEqualsWithDelta(10.0, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $memory['suggestion']);
        $this->assertStringContainsString('(policy: allkeys-lru)', (string) $memory['detail']);

        $memory = $this->checks(['memory' => ['used_memory' => 60, 'maxmemory' => 100]])['Memory usage'];
        $this->assertSame('warning', $memory['status']);
        $this->assertStringContainsString('may evict keys', (string) $memory['suggestion']);

        $memory = $this->checks(['memory' => ['used_memory' => 90, 'maxmemory' => 100, 'maxmemory_policy' => 'noeviction']])['Memory usage'];
        $this->assertSame('critical', $memory['status']);
        $this->assertEqualsWithDelta(90.0, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('writes will start to fail', (string) $memory['suggestion']);
    }

    public function testHitRateCheck(): void {
        $hit_rate = $this->checks([])['Hit rate'];
        $this->assertSame('critical', $hit_rate['status']);
        $this->assertEqualsWithDelta(0.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);

        $hit_rate = $this->checks(['stats' => ['keyspace_hits' => 60, 'keyspace_misses' => 40]])['Hit rate'];
        $this->assertSame('warning', $hit_rate['status']);
        $this->assertEqualsWithDelta(60.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('hit rate can be normal', (string) $hit_rate['suggestion']);

        $hit_rate = $this->checks(['stats' => ['keyspace_hits' => 90, 'keyspace_misses' => 10]])['Hit rate'];
        $this->assertSame('healthy', $hit_rate['status']);
        $this->assertEqualsWithDelta(90.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $hit_rate['suggestion']);
    }

    public function testEvictedKeysCheck(): void {
        $evicted = $this->checks([])['Evicted keys'];
        $this->assertSame('healthy', $evicted['status']);
        $this->assertSame(0, $evicted['utilization']);
        $this->assertSame('', $evicted['suggestion']);

        $evicted = $this->checks(['stats' => ['evicted_keys' => 5]])['Evicted keys'];
        $this->assertSame('warning', $evicted['status']);
        $this->assertSame(100, $evicted['utilization']);
        $this->assertStringContainsString('memory limit', (string) $evicted['suggestion']);
    }

    public function testClientsCheck(): void {
        $clients = $this->checks(['clients' => ['connected_clients' => 5, 'maxclients' => 100]])['Clients'];
        $this->assertSame('healthy', $clients['status']);
        $this->assertEqualsWithDelta(5.0, $clients['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('5 of 100 clients', (string) $clients['detail']);

        $clients = $this->checks(['clients' => ['connected_clients' => 5, 'maxclients' => 100, 'blocked_clients' => 2]])['Clients'];
        $this->assertStringContainsString('2 blocked', (string) $clients['detail']);

        $clients = $this->checks(['clients' => ['connected_clients' => 7]])['Clients'];
        $this->assertSame('healthy', $clients['status']);
        $this->assertEqualsWithDelta(0.0, $clients['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('7 clients', (string) $clients['detail']);

        $clients = $this->checks(['clients' => ['connected_clients' => 90, 'maxclients' => 100]])['Clients'];
        $this->assertSame('critical', $clients['status']);
        $this->assertStringContainsString('raising maxclients', (string) $clients['suggestion']);

        $clients = $this->checks([
            'clients' => ['connected_clients' => 1, 'maxclients' => 100],
            'stats'   => ['rejected_connections' => 3],
        ])['Clients'];
        $this->assertSame('critical', $clients['status']);
        $this->assertEqualsWithDelta(100.0, $clients['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('rejected', (string) $clients['suggestion']);
    }
}
