<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use RobiNN\Pca\Format;

trait APCuPanels {
    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(): array {
        $info = apcu_cache_info(true);
        $memory_info = apcu_sma_info(true);

        $total_memory = $memory_info['num_seg'] * $memory_info['seg_size'];
        $memory_used = $total_memory - $memory_info['avail_mem'];
        $memory_usage = round(($memory_used / $total_memory) * 100, 2);

        $num_hits = (int) $info['num_hits'];
        $num_misses = (int) $info['num_misses'];
        $hit_rate = $num_hits !== 0 ? round(($num_hits / ($num_hits + $num_misses)) * 100, 2) : 0;

        return [
            [
                'title' => 'APCu extension v'.phpversion('apcu'),
                'data'  => [
                    'Start time'       => Format::time($info['start_time']),
                    'Uptime'           => Format::seconds(time() - $info['start_time'], false),
                    'Cache full count' => $info['expunges'],
                ],
            ],
            [
                'title' => 'Memory',
                'data'  => [
                    'Type'  => $info['memory_type'].' - '.$memory_info['num_seg'].' segment(s)',
                    'Total' => Format::bytes((int) $total_memory, 0),
                    ['Used', Format::bytes((int) $memory_used).' ('.$memory_usage.'%)', $memory_usage],
                    'Free'  => Format::bytes((int) $memory_info['avail_mem']),
                ],
            ],
            [
                'title' => 'Stats',
                'data'  => [
                    'Slots'    => $info['num_slots'],
                    'Keys'     => Format::number((int) $info['num_entries']),
                    ['Hits / Misses', Format::number($num_hits).' / '.Format::number($num_misses).' (Rate '.$hit_rate.'%)', $hit_rate, 'higher'],
                    'Expunges' => Format::number((int) $info['expunges']),
                ],
            ],
        ];
    }
}
