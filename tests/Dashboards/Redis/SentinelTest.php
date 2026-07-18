<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Sentinel;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

final class SentinelTest extends TestCase {
    /**
     * @var array<int, string>
     */
    private array $sentinels = ['127.0.0.1:26379', '127.0.0.1:26380', '127.0.0.1:26381'];

    private string $master_name = 'mymaster';

    protected function setUp(): void {
        $server = Config::get('redis')[0] ?? [];

        if (!empty($server['sentinels']) && is_array($server['sentinels'])) {
            $this->sentinels = $server['sentinels'];
            $this->master_name = $server['sentinelmaster'] ?? $this->master_name;
        }
    }

    private function skipWithoutSentinel(): void {
        $socket = @stream_socket_client('tcp://'.$this->sentinels[0], $errno, $errstr, 1);

        if ($socket === false) {
            self::markTestSkipped('No Sentinel on '.$this->sentinels[0].'.');
        }

        fclose($socket);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function server(array $extra = []): array {
        return array_merge(['sentinels' => $this->sentinels, 'sentinelmaster' => $this->master_name], $extra);
    }

    /**
     * @return Iterator<array<int, string>>
     */
    public static function clientProvider(): Iterator {
        yield 'phpredis' => ['redis'];
        yield 'predis' => ['predis'];
    }

    /**
     * @throws DashboardException
     */
    #[DataProvider('clientProvider')]
    public function testResolvesTheMaster(string $client): void {
        $this->skipWithoutSentinel();

        $master = (new Sentinel($this->server(), $client))->masterAddress();

        $this->assertSame('127.0.0.1', $master['host']);
        $this->assertIsInt($master['port']);
        $this->assertGreaterThan(0, $master['port']);
    }

    /**
     * @throws DashboardException
     */
    #[DataProvider('clientProvider')]
    public function testSkipsAnUnreachableSentinel(string $client): void {
        $this->skipWithoutSentinel();

        $expected = (new Sentinel($this->server(), $client))->masterAddress();
        $server = $this->server(['sentinels' => ['127.0.0.1:26999', ...$this->sentinels]]);

        $this->assertSame($expected, (new Sentinel($server, $client))->masterAddress());
    }

    /**
     * @throws DashboardException
     */
    #[DataProvider('clientProvider')]
    public function testDefaultsToMymasterAndThe26379Port(string $client): void {
        $this->skipWithoutSentinel();

        $expected = (new Sentinel($this->server(), $client))->masterAddress();
        $server = ['sentinels' => ['127.0.0.1']];

        $this->assertSame($expected, (new Sentinel($server, $client))->masterAddress());
    }

    #[DataProvider('clientProvider')]
    public function testUnknownMasterNameIsReported(string $client): void {
        $this->skipWithoutSentinel();

        $this->expectException(DashboardException::class);
        $this->expectExceptionMessageMatches('/does not monitor "pu-no-such-master"/');

        (new Sentinel($this->server(['sentinelmaster' => 'pu-no-such-master']), $client))->masterAddress();
    }

    #[DataProvider('clientProvider')]
    public function testEverySentinelBeingDownIsReported(string $client): void {
        $server = $this->server(['sentinels' => ['127.0.0.1:26998', '127.0.0.1:26999']]);

        $this->expectException(DashboardException::class);
        $this->expectExceptionMessageMatches('/No sentinel could resolve the master/');
        $this->expectExceptionMessageMatches('/26998.+26999/s');

        (new Sentinel($server, $client))->masterAddress();
    }

    /**
     * @throws DashboardException
     */
    #[DataProvider('clientProvider')]
    public function testDashboardConnectsToTheMaster(string $client): void {
        $this->skipWithoutSentinel();

        $dashboard = new RedisDashboard(new Template(), $client);
        $redis = $dashboard->connect($this->server());

        $this->assertTrue($dashboard->is_sentinel);
        $this->assertFalse($dashboard->is_cluster);
        $this->assertSame('master', $redis->getInfo('replication')['role'] ?? null);

        $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $dashboard->sentinel_master);
        $this->assertSame($dashboard->sentinel_master, '127.0.0.1:'.$redis->getInfo('server')['tcp_port']);
    }

    public function testIsConfigured(): void {
        $this->assertTrue(Sentinel::isConfigured(['sentinels' => ['127.0.0.1:26379']]));
        $this->assertFalse(Sentinel::isConfigured(['host' => '127.0.0.1']));
        $this->assertFalse(Sentinel::isConfigured(['sentinels' => []]));
        $this->assertFalse(Sentinel::isConfigured(['sentinels' => '127.0.0.1:26379'])); // not a list
    }

    public function testServerTitleShowsTheMasterName(): void {
        $server = ['name' => 'Sentinel', 'host' => '127.0.0.1', 'port' => 6379, 'sentinels' => $this->sentinels];

        $this->assertSame('Sentinel - mymaster', Helpers::getServerTitle($server));
        $this->assertSame('Sentinel - other', Helpers::getServerTitle($server + ['sentinelmaster' => 'other']));
        $this->assertSame('Sentinel - 127.0.0.1:6379', Helpers::getServerTitle(['name' => 'Sentinel', 'host' => '127.0.0.1', 'port' => 6379]));
    }
}
