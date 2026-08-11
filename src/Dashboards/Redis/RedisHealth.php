<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;

trait RedisHealth {
    /**
     * A replica this far behind its master is no longer a warm copy of it.
     */
    private int $replication_lag_seconds = 10;

    /**
     * These stay "ok" while the matching persistence method is turned off, so anything else is a real failure.
     *
     * @var array<string, string>
     */
    private array $persistence_statuses = [
        'rdb_last_bgsave_status'    => 'the last RDB save failed',
        'aof_last_write_status'     => 'the last AOF write failed',
        'aof_last_bgrewrite_status' => 'the last AOF rewrite failed',
    ];

    /**
     * @param array<string, array<string, mixed>> $info
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHealthChecks(array $info): array {
        $memory = $info['memory'] ?? [];
        $stats = $info['stats'] ?? [];
        $clients = $info['clients'] ?? [];

        $checks = [
            $this->memoryCheck($memory),
            $this->hitRateCheck($stats),
            $this->evictedKeysCheck($stats),
            $this->clientsCheck($clients, $stats),
            $this->persistenceCheck($info['persistence'] ?? []),
            $this->replicationCheck($info['replication'] ?? []),
        ];

        return array_values(array_filter($checks, static fn (?array $check): bool => $check !== null));
    }

    /**
     * In a cluster a field holds one value per node and collapses into a single one only when every node agrees,
     * so anything read out of INFO has to survive both shapes.
     *
     * @param array<string, mixed> $section
     *
     * @return array<int, string>
     */
    private function fieldValues(array $section, string $field): array {
        $value = $section[$field] ?? null;

        if ($value === null || $value === '') {
            return [];
        }

        return array_map(strval(...), is_array($value) ? array_values($value) : [$value]);
    }

    /**
     * The worst node decides, so numbers are taken at their highest.
     *
     * @param array<string, mixed> $section
     */
    private function fieldMax(array $section, string $field): int {
        $values = array_filter($this->fieldValues($section, $field), is_numeric(...));

        return $values === [] ? 0 : (int) max(array_map(intval(...), $values));
    }

    /**
     * @param array<string, mixed> $memory
     *
     * @return array<string, mixed>
     */
    private function memoryCheck(array $memory): array {
        $used = (int) ($memory['used_memory'] ?? 0);
        $max = (int) ($memory['maxmemory'] ?? 0);

        if ($max <= 0) {
            return [
                'name'        => 'Memory usage',
                'directive'   => 'maxmemory',
                'utilization' => 0,
                'status'      => 'info',
                'detail'      => Format::bytes($used).' used, no memory limit set (maxmemory = 0)',
                'suggestion'  => '',
            ];
        }

        $utilization = ($used / $max) * 100;
        $status = Helpers::utilizationStatus($utilization);
        $policy = (string) ($memory['maxmemory_policy'] ?? '');
        $suggestion = '';

        if ($status !== 'healthy') {
            $suggestion = 'Approaching the memory limit. When it is reached, Redis applies the maxmemory-policy';
            $suggestion .= $policy === 'noeviction' ? ' (currently "noeviction", so writes will start to fail)' : ' and may evict keys';
            $suggestion .= '. Consider raising maxmemory.';
        }

        return [
            'name'        => 'Memory usage',
            'directive'   => 'maxmemory',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => Format::bytes($used).' of '.Format::bytes($max).' used'.($policy !== '' ? ' (policy: '.$policy.')' : ''),
            'suggestion'  => $suggestion,
        ];
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    private function hitRateCheck(array $stats): array {
        $hits = (int) ($stats['keyspace_hits'] ?? 0);
        $misses = (int) ($stats['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        $hit_rate = $total > 0 ? ($hits / $total) * 100 : 0;
        $status = Helpers::hitRateStatus($hit_rate);

        return [
            'name'        => 'Hit rate',
            'directive'   => '',
            'utilization' => round($hit_rate, 2),
            'status'      => $status,
            'detail'      => Format::number($hit_rate, 2).'% ('.Format::number($hits).' hits / '.Format::number($total).' lookups)',
            'suggestion'  => $status !== 'healthy' ? 'A low hit rate can be normal right after startup or on low-traffic servers. Otherwise keys may be evicted or expiring too soon, check maxmemory and your TTLs.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    private function evictedKeysCheck(array $stats): array {
        $evicted = (int) ($stats['evicted_keys'] ?? 0);
        $status = $evicted === 0 ? 'healthy' : 'warning';

        return [
            'name'        => 'Evicted keys',
            'directive'   => '',
            'utilization' => $evicted > 0 ? 100 : 0,
            'status'      => $status,
            'detail'      => Format::number($evicted).' keys evicted since startup',
            'suggestion'  => $status !== 'healthy' ? 'Keys have been evicted because Redis hit the memory limit. Raise maxmemory, store less, or review the maxmemory-policy.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $clients
     * @param array<string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    private function clientsCheck(array $clients, array $stats): array {
        $connected = (int) ($clients['connected_clients'] ?? 0);
        $max = (int) ($clients['maxclients'] ?? 0);
        $blocked = (int) ($clients['blocked_clients'] ?? 0);
        $rejected = (int) ($stats['rejected_connections'] ?? 0);
        $utilization = $max > 0 ? ($connected / $max) * 100 : 0;

        if ($rejected > 0) {
            $status = 'critical';
            $utilization = 100;
            $suggestion = Format::number($rejected).' connections have been rejected because the client limit was reached. Raise the maxclients setting.';
        } else {
            $status = Helpers::utilizationStatus($utilization);
            $suggestion = $status !== 'healthy' ? 'Client connection usage is high; consider raising maxclients.' : '';
        }

        $detail = $max > 0 ? Format::number($connected).' of '.Format::number($max).' clients' : Format::number($connected).' clients';

        if ($blocked > 0) {
            $detail .= ', '.Format::number($blocked).' blocked';
        }

        return [
            'name'        => 'Clients',
            'directive'   => '',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => $detail,
            'suggestion'  => $suggestion,
        ];
    }

    /**
     * @param array<string, mixed> $persistence
     *
     * @return array<string, mixed>|null
     */
    private function persistenceCheck(array $persistence): ?array {
        if ($persistence === []) {
            return null;
        }

        $check = ['name' => 'Persistence', 'directive' => 'appendonly, save', 'utilization' => 0, 'suggestion' => ''];

        if ($this->fieldMax($persistence, 'loading') > 0 || $this->fieldMax($persistence, 'async_loading') > 0) {
            return $check + [
                    'status' => 'info',
                    'detail' => 'Loading the dataset from disk, the keyspace is not complete yet',
                ];
        }

        $failures = [];

        foreach ($this->persistence_statuses as $field => $message) {
            foreach ($this->fieldValues($persistence, $field) as $status) {
                if ($status !== 'ok') {
                    $failures[$message] = $message;
                    break;
                }
            }
        }

        $repeated = $this->fieldMax($persistence, 'rdb_saves_consecutive_failures') + $this->fieldMax($persistence, 'aof_rewrites_consecutive_failures');
        $failed = $failures !== [] || $repeated > 0;

        if ($failed) {
            $check['utilization'] = 100;
            $check['suggestion'] = ucfirst(implode(' and ', $failures) ?: 'A background save failed').
                '. Redis keeps serving from memory, but everything written since the last successful save is lost on a restart. '.
                'The usual causes are a full disk and a fork that cannot get memory (see vm.overcommit_memory), the server log names the real one.';
        }

        return $check + [
                'status' => $failed ? 'critical' : 'healthy',
                'detail' => $this->persistenceDetail($persistence),
            ];
    }

    /**
     * @param array<string, mixed> $persistence
     */
    private function persistenceDetail(array $persistence): string {
        $aof_enabled = $this->fieldMax($persistence, 'aof_enabled') > 0;
        $last_save = $this->fieldMax($persistence, 'rdb_last_save_time');
        $changes = $this->fieldMax($persistence, 'rdb_changes_since_last_save');

        $detail = 'AOF '.($aof_enabled ? 'enabled' : 'disabled').', RDB ';
        $detail .= $last_save > 0 ? 'saved '.Format::timeDiff($last_save) : 'never saved';

        if ($changes > 0) {
            $detail .= ', '.Format::number($changes).' change'.($changes > 1 ? 's' : '').' since';
        }

        if ($this->fieldMax($persistence, 'rdb_bgsave_in_progress') > 0 || $this->fieldMax($persistence, 'aof_rewrite_in_progress') > 0) {
            $detail .= ', a background save is running';
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $replication
     *
     * @return array<string, mixed>|null
     */
    private function replicationCheck(array $replication): ?array {
        $roles = array_values(array_unique($this->fieldValues($replication, 'role')));

        if ($roles === []) {
            return null;
        }

        $check = ['name' => 'Replication', 'directive' => 'replicaof', 'utilization' => 0, 'suggestion' => ''];

        // A cluster answers with the role of every node, and a mix of them says nothing about a single server.
        if (count($roles) > 1) {
            sort($roles);

            return $check + [
                    'status' => 'info',
                    'detail' => 'Nodes report different roles: '.implode(', ', $roles),
                ];
        }

        return $roles[0] === 'master' ? $this->masterReplicationCheck($replication, $check) : $this->replicaReplicationCheck($replication, $check);
    }

    /**
     * @param array<string, mixed> $replication
     * @param array<string, mixed> $check
     *
     * @return array<string, mixed>
     */
    private function masterReplicationCheck(array $replication, array $check): array {
        $replicas = $this->fieldMax($replication, 'connected_slaves');

        if ($replicas === 0) {
            return $check + ['status' => 'info', 'detail' => 'Master without replicas'];
        }

        $offline = 0;
        $lag = 0;

        foreach (array_keys($replication) as $field) {
            if (preg_match('/^slave\d+$/', (string) $field) !== 1) {
                continue;
            }

            foreach ($this->fieldValues($replication, (string) $field) as $entry) {
                parse_str(str_replace(',', '&', $entry), $replica);

                if (($replica['state'] ?? 'online') !== 'online') {
                    $offline++;
                }

                $lag = max($lag, (int) ($replica['lag'] ?? 0));
            }
        }

        $detail = Format::number($replicas).' replica'.($replicas > 1 ? 's' : '').' connected';
        $check['status'] = 'healthy';

        if ($offline > 0) {
            $check['status'] = 'critical';
            $detail .= ', '.Format::number($offline).' not online';
            $check['suggestion'] = 'A replica is not in the online state, so it is either still syncing or lost. Until it catches up it holds an incomplete copy of the data.';
        } elseif ($lag > $this->replication_lag_seconds) {
            $check['status'] = 'warning';
            $check['suggestion'] = 'A replica has not acknowledged anything for a while. It is behind the master, so reads from it are stale and a failover would lose the difference. Check the network and the load on the replica.';
        }

        $check['utilization'] = $check['status'] === 'healthy' ? 0 : 100;
        $check['detail'] = $detail.', highest lag '.Format::number($lag).'s';

        return $check;
    }

    /**
     * @param array<string, mixed> $replication
     * @param array<string, mixed> $check
     *
     * @return array<string, mixed>
     */
    private function replicaReplicationCheck(array $replication, array $check): array {
        $link = $this->fieldValues($replication, 'master_link_status');
        $down = array_filter($link, static fn (string $state): bool => $state !== 'up');
        $syncing = $this->fieldMax($replication, 'master_sync_in_progress') > 0;
        $last_io = $this->fieldMax($replication, 'master_last_io_seconds_ago');

        $master = implode(':', array_filter([
            $this->fieldValues($replication, 'master_host')[0] ?? '',
            $this->fieldValues($replication, 'master_port')[0] ?? '',
        ]));

        $detail = 'Replica of '.($master !== '' ? $master : 'an unknown master').', link '.($down !== [] ? 'down' : 'up');

        if ($last_io >= 0 && $down === []) {
            $detail .= ', last contact '.Format::number($last_io).'s ago';
        }

        $behind = $this->fieldMax($replication, 'master_repl_offset') - $this->fieldMax($replication, 'slave_repl_offset');

        if ($behind > 0) {
            $detail .= ', '.Format::bytes($behind).' behind';
        }

        if ($down !== []) {
            $check['status'] = 'critical';
            $check['suggestion'] = 'The replica lost its master, so its data only gets older from here. Check that the master is up and reachable, and look for an auth error in the log.';
        } elseif ($syncing) {
            $check['status'] = 'warning';
            $detail .= ', full sync in progress';
            $check['suggestion'] = 'The replica is loading a full copy of the dataset. Until it finishes it cannot serve the whole keyspace.';
        } elseif ($last_io > $this->replication_lag_seconds) {
            $check['status'] = 'warning';
            $check['suggestion'] = 'The master has not been heard from for a while, even though the link is up. Check the network latency between them and repl-timeout.';
        } else {
            $check['status'] = 'healthy';
        }

        $check['utilization'] = $check['status'] === 'healthy' ? 0 : 100;
        $check['detail'] = $detail;

        return $check;
    }
}
