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
     * @param array<string, array<string, mixed>> $info
     *
     * @return array<int, array<string, mixed>>
     */
    private function getHealthChecks(array $info): array {
        $memory = $info['memory'] ?? [];
        $stats = $info['stats'] ?? [];
        $clients = $info['clients'] ?? [];

        return [
            $this->memoryCheck($memory),
            $this->hitRateCheck($stats),
            $this->evictedKeysCheck($stats),
            $this->clientsCheck($clients, $stats),
        ];
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
}
