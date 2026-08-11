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

    public function testChecksWithPersistenceAndReplication(): void {
        $names = array_column($this->dashboard->getHealthChecks([
            'persistence' => ['rdb_last_bgsave_status' => 'ok'],
            'replication' => ['role' => 'master'],
        ]), 'name');

        $this->assertSame(['Memory usage', 'Hit rate', 'Evicted keys', 'Clients', 'Persistence', 'Replication'], $names);
    }

    public function testMemoryCheckWithoutLimit(): void {
        $memory = $this->checks(['memory' => ['used_memory' => 100]])['Memory usage'];

        $this->assertSame('info', $memory['status']);
        $this->assertStringContainsString('no memory limit set', (string) $memory['detail']);
    }

    public function testMemoryCheckHealthy(): void {
        $memory = $this->checks(['memory' => ['used_memory' => 10, 'maxmemory' => 100, 'maxmemory_policy' => 'allkeys-lru']])['Memory usage'];

        $this->assertSame('healthy', $memory['status']);
        $this->assertEqualsWithDelta(10.0, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $memory['suggestion']);
        $this->assertStringContainsString('(policy: allkeys-lru)', (string) $memory['detail']);
    }

    public function testMemoryCheckWarning(): void {
        $memory = $this->checks(['memory' => ['used_memory' => 60, 'maxmemory' => 100]])['Memory usage'];

        $this->assertSame('warning', $memory['status']);
        $this->assertStringContainsString('may evict keys', (string) $memory['suggestion']);
    }

    public function testMemoryCheckCritical(): void {
        $memory = $this->checks(['memory' => ['used_memory' => 90, 'maxmemory' => 100, 'maxmemory_policy' => 'noeviction']])['Memory usage'];

        $this->assertSame('critical', $memory['status']);
        $this->assertEqualsWithDelta(90.0, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('writes will start to fail', (string) $memory['suggestion']);
    }

    public function testHitRateCheckWithoutStats(): void {
        $hit_rate = $this->checks([])['Hit rate'];

        $this->assertSame('critical', $hit_rate['status']);
        $this->assertEqualsWithDelta(0.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testHitRateCheckWarning(): void {
        $hit_rate = $this->checks(['stats' => ['keyspace_hits' => 60, 'keyspace_misses' => 40]])['Hit rate'];

        $this->assertSame('warning', $hit_rate['status']);
        $this->assertEqualsWithDelta(60.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('hit rate can be normal', (string) $hit_rate['suggestion']);
    }

    public function testHitRateCheckHealthy(): void {
        $hit_rate = $this->checks(['stats' => ['keyspace_hits' => 90, 'keyspace_misses' => 10]])['Hit rate'];

        $this->assertSame('healthy', $hit_rate['status']);
        $this->assertEqualsWithDelta(90.0, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $hit_rate['suggestion']);
    }

    public function testEvictedKeysCheckHealthy(): void {
        $evicted = $this->checks([])['Evicted keys'];

        $this->assertSame('healthy', $evicted['status']);
        $this->assertSame(0, $evicted['utilization']);
        $this->assertSame('', $evicted['suggestion']);
    }

    public function testEvictedKeysCheckWarning(): void {
        $evicted = $this->checks(['stats' => ['evicted_keys' => 5]])['Evicted keys'];

        $this->assertSame('warning', $evicted['status']);
        $this->assertSame(100, $evicted['utilization']);
        $this->assertStringContainsString('memory limit', (string) $evicted['suggestion']);
    }

    public function testClientsCheckHealthy(): void {
        $clients = $this->checks(['clients' => ['connected_clients' => 5, 'maxclients' => 100]])['Clients'];

        $this->assertSame('healthy', $clients['status']);
        $this->assertEqualsWithDelta(5.0, $clients['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('5 of 100 clients', (string) $clients['detail']);
    }

    public function testClientsCheckShowsBlockedClients(): void {
        $clients = $this->checks(['clients' => ['connected_clients' => 5, 'maxclients' => 100, 'blocked_clients' => 2]])['Clients'];

        $this->assertStringContainsString('2 blocked', (string) $clients['detail']);
    }

    public function testClientsCheckWithoutLimit(): void {
        $clients = $this->checks(['clients' => ['connected_clients' => 7]])['Clients'];

        $this->assertSame('healthy', $clients['status']);
        $this->assertEqualsWithDelta(0.0, $clients['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('7 clients', (string) $clients['detail']);
    }

    public function testClientsCheckNearTheLimit(): void {
        $clients = $this->checks(['clients' => ['connected_clients' => 90, 'maxclients' => 100]])['Clients'];

        $this->assertSame('critical', $clients['status']);
        $this->assertStringContainsString('raising maxclients', (string) $clients['suggestion']);
    }

    public function testClientsCheckWithRejectedConnections(): void {
        $clients = $this->checks([
            'clients' => ['connected_clients' => 1, 'maxclients' => 100],
            'stats'   => ['rejected_connections' => 3],
        ])['Clients'];

        $this->assertSame('critical', $clients['status']);
        $this->assertEqualsWithDelta(100.0, $clients['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('rejected', (string) $clients['suggestion']);
    }

    /**
     * @param array<string, mixed> $persistence
     *
     * @return array<string, mixed>
     */
    private function persistence(array $persistence): array {
        return $this->checks(['persistence' => $persistence])['Persistence'];
    }

    public function testPersistenceCheckHealthy(): void {
        $persistence = $this->persistence([
            'rdb_last_bgsave_status'      => 'ok',
            'aof_enabled'                 => 1,
            'aof_last_write_status'       => 'ok',
            'rdb_last_save_time'          => time() - 120,
            'rdb_changes_since_last_save' => 3,
        ]);

        $this->assertSame('healthy', $persistence['status']);
        $this->assertSame(0, $persistence['utilization']);
        $this->assertSame('', $persistence['suggestion']);
        $this->assertStringContainsString('AOF enabled', (string) $persistence['detail']);
        $this->assertStringContainsString('3 changes since', (string) $persistence['detail']);
    }

    public function testPersistenceCheckWithAFailedSave(): void {
        $persistence = $this->persistence(['rdb_last_bgsave_status' => 'err', 'rdb_last_save_time' => time() - 60]);

        $this->assertSame('critical', $persistence['status']);
        $this->assertSame(100, $persistence['utilization']);
        $this->assertStringContainsString('The last RDB save failed', (string) $persistence['suggestion']);
        $this->assertStringContainsString('lost on a restart', (string) $persistence['suggestion']);
    }

    public function testPersistenceCheckWithRepeatedAofFailures(): void {
        $persistence = $this->persistence([
            'rdb_last_bgsave_status'            => 'ok',
            'aof_enabled'                       => 1,
            'aof_last_write_status'             => 'ok',
            'aof_rewrites_consecutive_failures' => 4,
        ]);

        $this->assertSame('critical', $persistence['status']);
        $this->assertStringContainsString('A background save failed', (string) $persistence['suggestion']);
    }

    public function testPersistenceCheckWhileLoading(): void {
        $persistence = $this->persistence(['loading' => 1, 'rdb_last_bgsave_status' => 'err']);

        $this->assertSame('info', $persistence['status']);
        $this->assertStringContainsString('Loading the dataset', (string) $persistence['detail']);
    }

    public function testPersistenceCheckReportsARunningSave(): void {
        $detail = (string) $this->persistence([
            'rdb_last_bgsave_status' => 'ok',
            'rdb_bgsave_in_progress' => 1,
            'rdb_last_save_time'     => 0,
        ])['detail'];

        $this->assertStringContainsString('RDB never saved', $detail);
        $this->assertStringContainsString('a background save is running', $detail);
    }

    public function testPersistenceCheckWithOneValuePerClusterNode(): void {
        $persistence = $this->persistence([
            'rdb_last_bgsave_status' => ['ok', 'ok', 'err'],
            'rdb_last_save_time'     => [time() - 60, time() - 90],
        ]);

        $this->assertSame('critical', $persistence['status']);
        $this->assertStringContainsString('The last RDB save failed', (string) $persistence['suggestion']);
    }

    /**
     * @param array<string, mixed> $replication
     *
     * @return array<string, mixed>
     */
    private function replication(array $replication): array {
        return $this->checks(['replication' => $replication])['Replication'];
    }

    public function testReplicationCheckOfAMasterWithoutReplicas(): void {
        $replication = $this->replication(['role' => 'master', 'connected_slaves' => 0]);

        $this->assertSame('info', $replication['status']);
        $this->assertSame('Master without replicas', $replication['detail']);
    }

    public function testReplicationCheckOfAHealthyMaster(): void {
        $replication = $this->replication([
            'role'             => 'master',
            'connected_slaves' => 2,
            'slave0'           => 'ip=127.0.0.1,port=6380,state=online,offset=100,lag=0',
            'slave1'           => 'ip=127.0.0.1,port=6381,state=online,offset=100,lag=1',
        ]);

        $this->assertSame('healthy', $replication['status']);
        $this->assertSame('2 replicas connected, highest lag 1s', $replication['detail']);
        $this->assertSame('', $replication['suggestion']);
    }

    public function testReplicationCheckOfAMasterWithALaggingReplica(): void {
        $replication = $this->replication([
            'role'             => 'master',
            'connected_slaves' => 1,
            'slave0'           => 'ip=127.0.0.1,port=6380,state=online,offset=100,lag=42',
        ]);

        $this->assertSame('warning', $replication['status']);
        $this->assertSame(100, $replication['utilization']);
        $this->assertStringContainsString('reads from it are stale', (string) $replication['suggestion']);
    }

    public function testReplicationCheckOfAMasterWithAReplicaThatIsNotOnline(): void {
        $replication = $this->replication([
            'role'             => 'master',
            'connected_slaves' => 1,
            'slave0'           => 'ip=127.0.0.1,port=6380,state=wait_bgsave,offset=0,lag=0',
        ]);

        $this->assertSame('critical', $replication['status']);
        $this->assertStringContainsString('1 not online', (string) $replication['detail']);
    }

    public function testReplicationCheckOfAHealthyReplica(): void {
        $replication = $this->replication([
            'role'                       => 'slave',
            'master_host'                => '127.0.0.1',
            'master_port'                => 6379,
            'master_link_status'         => 'up',
            'master_last_io_seconds_ago' => 1,
            'master_repl_offset'         => 2048,
            'slave_repl_offset'          => 1024,
        ]);

        $this->assertSame('healthy', $replication['status']);
        $this->assertStringContainsString('Replica of 127.0.0.1:6379, link up', (string) $replication['detail']);
        $this->assertStringContainsString('last contact 1s ago', (string) $replication['detail']);
        $this->assertStringContainsString('KB behind', (string) $replication['detail']);
    }

    public function testReplicationCheckOfAReplicaWithABrokenLink(): void {
        $replication = $this->replication(['role' => 'slave', 'master_link_status' => 'down']);

        $this->assertSame('critical', $replication['status']);
        $this->assertStringContainsString('link down', (string) $replication['detail']);
        $this->assertStringContainsString('lost its master', (string) $replication['suggestion']);
    }

    public function testReplicationCheckOfASyncingReplica(): void {
        $replication = $this->replication([
            'role'                    => 'slave',
            'master_link_status'      => 'up',
            'master_sync_in_progress' => 1,
        ]);

        $this->assertSame('warning', $replication['status']);
        $this->assertStringContainsString('full sync in progress', (string) $replication['detail']);
    }

    public function testReplicationCheckOfAQuietMaster(): void {
        $replication = $this->replication([
            'role'                       => 'slave',
            'master_link_status'         => 'up',
            'master_last_io_seconds_ago' => 30,
        ]);

        $this->assertSame('warning', $replication['status']);
        $this->assertStringContainsString('repl-timeout', (string) $replication['suggestion']);
    }

    public function testReplicationCheckWithMixedRoles(): void {
        $replication = $this->replication(['role' => ['master', 'slave', 'master']]);

        $this->assertSame('info', $replication['status']);
        $this->assertSame('Nodes report different roles: master, slave', $replication['detail']);
    }
}
