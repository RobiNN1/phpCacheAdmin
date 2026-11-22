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
     * Below this many gets the hit rate is not judged.
     */
    private const HIT_RATE_MIN_GETS = 1000;

    /**
     * In seconds, a younger server has a cold cache.
     */
    private const RECENT_RESTART = 3600;

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
            $this->unreadEvictionsCheck($info),
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
            'suggestion'  => $status !== 'healthy' ? 'Memory is filling up. When it is full, Memcached evicts older items to make room. Consider raising the memory limit (-m).' : '',
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
        $uptime = (int) ($info['uptime'] ?? 0);
        $hit_rate = $cmd_get > 0 ? ($get_hits / $cmd_get) * 100 : 0;
        $detail = Format::number($hit_rate, 2).'% ('.Format::number($get_hits).' hits / '.Format::number($cmd_get).' gets)';

        // Right after a restart or on an idle server the rate says nothing yet.
        if ($cmd_get < self::HIT_RATE_MIN_GETS || ($uptime > 0 && $uptime < self::RECENT_RESTART)) {
            return [
                'name'        => 'Hit rate',
                'directive'   => '',
                'utilization' => round($hit_rate, 2),
                'status'      => 'info',
                'detail'      => $detail,
                'suggestion'  => $cmd_get < self::HIT_RATE_MIN_GETS
                    ? 'Too few gets to judge the hit rate.'
                    : 'The server was restarted '.Format::seconds($uptime).' ago, the cache is still warming up.',
            ];
        }

        $status = Helpers::hitRateStatus($hit_rate);

        return [
            'name'        => 'Hit rate',
            'directive'   => '',
            'utilization' => round($hit_rate, 2),
            'status'      => $status,
            'detail'      => $detail,
            'suggestion'  => $status !== 'healthy' ? 'Keys may be missing, or evicted or expiring before they are read, check the memory limit and your TTLs.' : '',
        ];
    }

    /**
     * Items evicted before anyone read them, the working set does not fit into memory.
     *
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function unreadEvictionsCheck(array $info): array {
        $evictions = (int) ($info['evictions'] ?? 0);
        $unfetched = (int) ($info['evicted_unfetched'] ?? 0);
        $share = $evictions > 0 ? ($unfetched / $evictions) * 100 : 0;

        return [
            'name'        => 'Evicted before read',
            'directive'   => '',
            'utilization' => round($share, 2),
            'status'      => $unfetched > 0 ? 'warning' : 'healthy',
            'detail'      => Format::number($unfetched).' items evicted before anyone read them ('.Format::number($share, 2).'% of evictions)',
            'suggestion'  => $unfetched > 0 ? 'Items are pushed out before they are used, storing them was wasted work. The memory is too small for the working set, raise it (-m) or cache less.' : '',
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
        $listen_disabled = (int) ($info['listen_disabled_num'] ?? 0);
        $utilization = $max > 0 ? ($current / $max) * 100 : 0;

        if ($rejected > 0) {
            $status = 'critical';
            $utilization = 100;
            $suggestion = Format::number($rejected).' connections have been rejected because the connection limit was reached. Raise it with the -c option.';
        } elseif ($listen_disabled > 0) {
            $status = 'critical';
            $utilization = 100;
            $suggestion = 'The server stopped accepting new connections '.Format::number($listen_disabled).' times because the connection limit was reached. Raise it with the -c option.';
        } else {
            $status = Helpers::utilizationStatus($utilization);
            $suggestion = $status !== 'healthy' ? 'Connection usage is high. Consider raising the connection limit (-c).' : '';
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
