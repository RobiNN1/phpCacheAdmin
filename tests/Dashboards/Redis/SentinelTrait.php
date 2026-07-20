<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Sentinel;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Template;

trait SentinelTrait {
    private function skipWithoutSentinelMode(): void {
        if (!self::$is_sentinel) {
            $this->markTestSkipped('Not running in the sentinel mode, run "composer test-sentinel".');
        }
    }

    /**
     * @return array<int, string>
     */
    private function sentinels(): array {
        return Config::get('redis')[0]['sentinels'];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function sentinelServer(array $extra = []): array {
        $server = Config::get('redis')[0];

        return array_merge([
            'sentinels'      => $server['sentinels'],
            'sentinelmaster' => $server['sentinelmaster'] ?? 'mymaster',
        ], $extra);
    }

    /**
     * @throws DashboardException
     */
    public function testSkipsAnUnreachableSentinel(): void {
        $this->skipWithoutSentinelMode();

        $expected = (new Sentinel($this->sentinelServer(), $this->client))->masterAddress();
        $server = $this->sentinelServer(['sentinels' => ['127.0.0.1:26999', ...$this->sentinels()]]);

        $this->assertSame($expected, (new Sentinel($server, $this->client))->masterAddress());
    }

    /**
     * @throws DashboardException
     */
    public function testDefaultsToMymasterAndThe26379Port(): void {
        $this->skipWithoutSentinelMode();

        $expected = (new Sentinel($this->sentinelServer(), $this->client))->masterAddress();

        $this->assertSame($expected, (new Sentinel(['sentinels' => ['127.0.0.1']], $this->client))->masterAddress());
    }

    /**
     * @throws DashboardException
     */
    public function testUnknownMasterNameIsReported(): void {
        $this->skipWithoutSentinelMode();

        $this->expectException(DashboardException::class);
        $this->expectExceptionMessageMatches('/does not monitor "pu-no-such-master"/');

        (new Sentinel($this->sentinelServer(['sentinelmaster' => 'pu-no-such-master']), $this->client))->masterAddress();
    }

    /**
     * @throws DashboardException
     */
    public function testEverySentinelBeingDownIsReported(): void {
        $this->skipWithoutSentinelMode();

        $server = $this->sentinelServer(['sentinels' => ['127.0.0.1:26998', '127.0.0.1:26999']]);

        $this->expectException(DashboardException::class);
        $this->expectExceptionMessageMatches('/No sentinel could resolve the master/');
        $this->expectExceptionMessageMatches('/26998.+26999/s');

        (new Sentinel($server, $this->client))->masterAddress();
    }

    /**
     * @throws DashboardException
     */
    public function testDashboardConnectsToTheMaster(): void {
        $this->skipWithoutSentinelMode();

        $dashboard = new RedisDashboard(new Template(), $this->client);
        $redis = $dashboard->connect($this->sentinelServer());

        $this->assertTrue($dashboard->is_sentinel);
        $this->assertFalse($dashboard->is_cluster);
        $this->assertSame('master', $redis->getInfo('replication')['role'] ?? null);

        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $dashboard->sentinel_master);
        $this->assertSame($dashboard->sentinel_master, '127.0.0.1:'.$redis->getInfo('server')['tcp_port']);
    }
}
