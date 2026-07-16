<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;

trait MemcachedHealth {
    /**
     * @param array<string, mixed> $info
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHealthChecks(array $info): array {
        return [
            $this->memoryCheck($info),
            $this->hitRateCheck($info),
            $this->evictionsCheck($info),
            $this->connectionsCheck($info),
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function memoryCheck(array $info): array {
        $limit = (int) ($info['limit_maxbytes'] ?? 0);
        $bytes = (int) ($info['bytes'] ?? 0);
        $utilization = $limit > 0 ? ($bytes / $limit) * 100 : 0;
        $status = Helpers::utilizationStatus($utilization);

        return [
            'name'        => 'Memory usage',
            'directive'   => '',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => Format::bytes($bytes).' of '.Format::bytes($limit).' used',
            'suggestion'  => $status !== 'healthy' ? 'Memory is filling up. When it is full, Memcached evicts older items to make room; consider raising the memory limit (-m).' : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function hitRateCheck(array $info): array {
        $cmd_get = (int) ($info['cmd_get'] ?? 0);
        $get_hits = (int) ($info['get_hits'] ?? 0);
        $hit_rate = $cmd_get > 0 ? ($get_hits / $cmd_get) * 100 : 0;
        $status = Helpers::hitRateStatus($hit_rate);

        return [
            'name'        => 'Hit rate',
            'directive'   => '',
            'utilization' => round($hit_rate, 2),
            'status'      => $status,
            'detail'      => Format::number($hit_rate, 2).'% ('.Format::number($get_hits).' hits / '.Format::number($cmd_get).' gets)',
            'suggestion'  => $status !== 'healthy' ? 'A low hit rate can be normal right after startup or on low-traffic servers. Otherwise keys may be evicted or expiring too soon, check the memory limit and your TTLs.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function evictionsCheck(array $info): array {
        $evictions = (int) ($info['evictions'] ?? 0);
        $total_items = (int) ($info['total_items'] ?? 0);
        $eviction_rate = $total_items > 0 ? ($evictions / $total_items) * 100 : 0;

        if ($evictions === 0) {
            $status = 'healthy';
        } elseif ($eviction_rate > 10) {
            $status = 'critical';
        } else {
            $status = 'warning';
        }

        return [
            'name'        => 'Evictions',
            'directive'   => '',
            'utilization' => round(min($eviction_rate, 100), 2),
            'status'      => $status,
            'detail'      => Format::number($evictions).' evictions ('.Format::number($eviction_rate, 2).'% of stored items)',
            'suggestion'  => $status !== 'healthy' ? 'Items are being evicted because the cache is full. Raise the memory limit (-m), store less, or lower TTLs.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function connectionsCheck(array $info): array {
        $current = (int) ($info['curr_connections'] ?? 0);
        $max = (int) ($info['max_connections'] ?? 0);
        $rejected = (int) ($info['rejected_connections'] ?? 0);
        $utilization = $max > 0 ? ($current / $max) * 100 : 0;

        if ($rejected > 0) {
            $status = 'critical';
            $utilization = 100;
            $suggestion = Format::number($rejected).' connections have been rejected because the connection limit was reached. Raise it with the -c option.';
        } else {
            $status = Helpers::utilizationStatus($utilization);
            $suggestion = $status !== 'healthy' ? 'Connection usage is high; consider raising the connection limit (-c).' : '';
        }

        return [
            'name'        => 'Connections',
            'directive'   => '',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => $max > 0 ? Format::number($current).' of '.Format::number($max).' connections' : Format::number($current).' connections',
            'suggestion'  => $suggestion,
        ];
    }
}
