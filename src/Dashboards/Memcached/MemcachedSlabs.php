<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;

trait MemcachedSlabs {
    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function slabsTab(): array {
        $slabs_stats = $this->memcached->getSlabsStats();
        $items_stats = $this->memcached->getItemsStats();
        $uptime = (int) ($this->memcached->getServerStats()['uptime'] ?? 0);

        $slabs = [];
        $total_wasted = 0;
        $used_slabs = 0;

        foreach ((array) $slabs_stats['slabs'] as $slab_id => $slab) {
            if (!is_array($slab)) {
                continue;
            }

            $item = $items_stats[$slab_id] ?? [];
            $requested = is_array($item) ? (int) ($item['mem_requested'] ?? 0) : 0;
            $wasted = $this->slabWastedMemory($slab, $requested);

            $total_wasted += $wasted;

            if ((int) ($slab['used_chunks'] ?? 0) > 0) {
                $used_slabs++;
            }

            $slabs[$slab_id] = $this->slabPanelData($slab, $requested, $wasted, $uptime);
        }

        return [
            'slabs'        => $slabs,
            'meta'         => $slabs_stats['meta'],
            'total_wasted' => $total_wasted,
            'used_slabs'   => $used_slabs,
        ];
    }

    /**
     * @param array<string, mixed> $slab
     */
    private function slabWastedMemory(array $slab, int $requested): int {
        $total_chunks = (int) ($slab['total_chunks'] ?? 0);
        $used_chunks = (int) ($slab['used_chunks'] ?? 0);
        $chunk_size = (int) ($slab['chunk_size'] ?? 0);
        $allocated = $total_chunks * $chunk_size;

        if ($allocated < $requested) {
            return ($total_chunks - $used_chunks) * $chunk_size;
        }

        return $allocated - $requested;
    }

    /**
     * @param array<string, mixed> $slab
     */
    private function slabRequestRate(array $slab, int $uptime): float {
        if ($uptime <= 0) {
            return 0;
        }

        $requests = 0;

        foreach (['get_hits', 'cmd_set', 'delete_hits', 'cas_hits', 'cas_badval', 'incr_hits', 'decr_hits'] as $field) {
            $requests += (int) ($slab[$field] ?? 0);
        }

        return round($requests / $uptime, 2);
    }

    /**
     * @param array<string, mixed> $slab
     *
     * @return array<int|string, mixed>
     */
    private function slabPanelData(array $slab, int $requested, int $wasted, int $uptime): array {
        $allocated = (int) ($slab['total_chunks'] ?? 0) * (int) ($slab['chunk_size'] ?? 0);
        $wasted_percentage = $allocated > 0 ? round(($wasted / $allocated) * 100, 2) : 0;

        $chunks = [
            'chunk_size'      => ['Chunk Size', 'bytes'],
            'chunks_per_page' => ['Chunks per Page', 'number'],
            'total_pages'     => ['Total Pages', 'number'],
            'total_chunks'    => ['Total Chunks', 'number'],
            'used_chunks'     => ['Used Chunks', 'number'],
            'free_chunks'     => ['Free Chunks', 'number'],
            'free_chunks_end' => ['Free Chunks (End)', 'number'],
        ];

        $commands = [
            'get_hits'    => ['GET Hits', 'number'],
            'cmd_set'     => ['SET Commands', 'number'],
            'delete_hits' => ['DELETE Hits', 'number'],
            'incr_hits'   => ['INCREMENT Hits', 'number'],
            'decr_hits'   => ['DECREMENT Hits', 'number'],
            'cas_hits'    => ['CAS Hits', 'number'],
            'cas_badval'  => ['CAS Bad Value', 'number'],
            'touch_hits'  => ['TOUCH Hits', 'number'],
        ];

        return array_merge(
            Helpers::formatFields($chunks, $slab),
            [
                'Allocated'        => Format::bytes($allocated),
                'Memory Requested' => Format::bytes($requested),
                ['Memory Wasted', Format::bytes($wasted).' ('.$wasted_percentage.'%)', $wasted_percentage],
                'Request Rate'     => Format::number($this->slabRequestRate($slab, $uptime), 2).'/s',
            ],
            Helpers::formatFields($commands, $slab)
        );
    }
}
