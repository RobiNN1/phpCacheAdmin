<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;

trait APCuHealth {
    /**
     * @param array<string, mixed>|null $info
     * @param array<string, mixed>|null $sma
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHealthChecks(?array $info = null, ?array $sma = null): array {
        $info ??= (array) apcu_cache_info(true);
        $sma ??= apcu_sma_info();

        return [
            $this->memoryCheck($sma),
            $this->hitRateCheck($info),
            $this->fragmentationCheck($sma),
            $this->expungesCheck($info),
        ];
    }

    /**
     * Free memory that is split into many small blocks.
     *
     * @param array<string, mixed> $sma
     *
     * @return array{percentage: float, blocks: int, free: int, largest: int}
     */
    public function fragmentation(array $sma): array {
        $blocks = 0;
        $free = 0;
        $largest = 0;

        foreach ((array) ($sma['block_lists'] ?? []) as $block_list) {
            foreach ((array) $block_list as $block) {
                if (!isset($block['size'])) {
                    continue;
                }

                $size = (int) $block['size'];
                $blocks++;
                $free += $size;
                $largest = max($largest, $size);
            }
        }

        // Everything free sitting in a single block is not fragmented, no matter how many bytes it is.
        $percentage = $free > 0 ? (($free - $largest) / $free) * 100 : 0.0;

        return [
            'percentage' => round($percentage, 2),
            'blocks'     => $blocks,
            'free'       => $free,
            'largest'    => $largest,
        ];
    }

    /**
     * @param array<string, mixed> $sma
     *
     * @return array<string, mixed>
     */
    private function memoryCheck(array $sma): array {
        $total = (int) ($sma['num_seg'] ?? 1) * (int) ($sma['seg_size'] ?? 0);
        $available = (int) ($sma['avail_mem'] ?? 0);
        $used = $total - $available;
        $utilization = $total > 0 ? ($used / $total) * 100 : 0;
        $status = Helpers::utilizationStatus($utilization);

        return [
            'name'        => 'Memory usage',
            'directive'   => 'apc.shm_size',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => Format::bytes($used).' of '.Format::bytes($total).' used',
            'suggestion'  => $status !== 'healthy' ? 'The cache is filling up. APCu has no LRU eviction, once it is full it throws away everything at once, so increase apc.shm_size.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function hitRateCheck(array $info): array {
        $hits = (int) ($info['num_hits'] ?? 0);
        $misses = (int) ($info['num_misses'] ?? 0);
        $total = $hits + $misses;
        $hit_rate = $total > 0 ? ($hits / $total) * 100 : 0;
        $status = Helpers::hitRateStatus($hit_rate);

        return [
            'name'        => 'Hit rate',
            'directive'   => '',
            'utilization' => round($hit_rate, 2),
            'status'      => $status,
            'detail'      => Format::number($hit_rate, 2).'% ('.Format::number($hits).' hits / '.Format::number($total).' lookups)',
            'suggestion'  => $status !== 'healthy' ? 'A low hit rate is normal right after a restart. Otherwise entries expire or are discarded before they are read again, check your TTLs and apc.shm_size.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $sma
     *
     * @return array<string, mixed>
     */
    private function fragmentationCheck(array $sma): array {
        $fragmentation = $this->fragmentation($sma);
        $percentage = $fragmentation['percentage'];

        $status = match (true) {
            $percentage > 50 => 'critical',
            $percentage > 25 => 'warning',
            default => 'healthy',
        };

        $detail = Format::number($fragmentation['blocks']).' free blocks, largest '.Format::bytes($fragmentation['largest']).
            ' of '.Format::bytes($fragmentation['free']).' free';

        return [
            'name'        => 'Fragmentation',
            'directive'   => '',
            'utilization' => $percentage,
            'status'      => $status,
            'detail'      => $detail,
            'suggestion'  => $status !== 'healthy' ? 'Free memory is scattered across many small blocks, so storing a large entry can fail even with free memory left. APCu cannot merge them back, only clearing the cache helps. Entries of a more uniform size and fewer deletes reduce it.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function expungesCheck(array $info): array {
        $expunges = (int) ($info['expunges'] ?? 0);
        $uptime = max(1, time() - (int) ($info['start_time'] ?? time()));
        $per_hour = $expunges / ($uptime / 3600);

        $status = match (true) {
            $expunges === 0 => 'healthy',
            $per_hour >= 1 => 'critical',
            default => 'warning',
        };

        return [
            'name'        => 'Cache full count',
            'directive'   => 'apc.shm_size',
            'utilization' => round(min($per_hour * 100, 100), 2),
            'status'      => $status,
            'detail'      => Format::number($expunges).' expunges in '.Format::seconds($uptime, false).' ('.Format::number($per_hour, 2).' per hour)',
            'suggestion'  => $status !== 'healthy' ? 'The cache ran out of memory and was discarded. Every expunge throws away all entries at once, which shows up as a burst of misses, so increase apc.shm_size.' : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthTab(): array {
        return ['checks' => $this->getHealthChecks()];
    }
}
