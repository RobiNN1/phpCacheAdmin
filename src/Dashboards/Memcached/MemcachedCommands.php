<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Format;

trait MemcachedCommands {
    private const COMMAND_COUNTERS = [
        'get'   => 'cmd_get',
        'set'   => 'cmd_set',
        'touch' => 'cmd_touch',
        'flush' => 'cmd_flush',
    ];

    /**
     * @param array<string, mixed> $stats
     */
    private function commandRequests(array $stats, string $command): int {
        $counter = self::COMMAND_COUNTERS[$command] ?? null;

        if ($counter !== null) {
            return (int) ($stats[$counter] ?? 0);
        }

        $requests = (int) ($stats[$command.'_hits'] ?? 0) + (int) ($stats[$command.'_misses'] ?? 0);

        return $command === 'cas' ? $requests + (int) ($stats['cas_badval'] ?? 0) : $requests;
    }

    /**
     * @param array<int|string, mixed> $info
     *
     * @return array<int|string, mixed>
     */
    private function commandsStatsData(array $info): array {
        $rate = (static fn (int $hits, int $total): float => $hits !== 0 && $total !== 0 ? round(($hits / $total) * 100, 2) : 0);

        $get_hit_rate = $rate($info['get_hits'], $info['cmd_get']);
        $delete_hit_rate = $rate($info['delete_hits'], $info['delete_hits'] + $info['delete_misses']);
        $incr_hit_rate = $rate($info['incr_hits'], $info['incr_hits'] + $info['incr_misses']);
        $decr_hit_rate = $rate($info['decr_hits'], $info['decr_hits'] + $info['decr_misses']);
        $cas_hit_rate = $rate($info['cas_hits'], $info['cas_hits'] + $info['cas_misses']);
        $touch_hit_rate = $rate($info['touch_hits'], $info['cmd_touch']);

        return [
            [
                'title' => 'get',
                'data'  => [
                    'Hits'   => Format::number($info['get_hits']),
                    'Misses' => Format::number($info['get_misses']),
                    ['Hit Rate', $get_hit_rate.'%', $get_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'delete',
                'data'  => [
                    'Hits'   => Format::number($info['delete_hits']),
                    'Misses' => Format::number($info['delete_misses']),
                    ['Hit Rate', $delete_hit_rate.'%', $delete_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'incr',
                'data'  => [
                    'Hits'   => Format::number($info['incr_hits']),
                    'Misses' => Format::number($info['incr_misses']),
                    ['Hit Rate', $incr_hit_rate.'%', $incr_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'decr',
                'data'  => [
                    'Hits'   => Format::number($info['decr_hits']),
                    'Misses' => Format::number($info['decr_misses']),
                    ['Hit Rate', $decr_hit_rate.'%', $decr_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'touch',
                'data'  => [
                    'Hits'   => Format::number($info['touch_hits']),
                    'Misses' => Format::number($info['touch_misses']),
                    ['Hit Rate', $touch_hit_rate.'%', $touch_hit_rate, 'higher'],
                ],
            ],
            [
                'title' => 'cas',
                'data'  => [
                    'Hits'      => Format::number($info['cas_hits']),
                    'Misses'    => Format::number($info['cas_misses']),
                    ['Hit Rate', $cas_hit_rate.'%', $cas_hit_rate, 'higher'],
                    'Bad Value' => $info['cas_badval'],
                ],
            ],
            [
                'title' => 'set',
                'data'  => [
                    'Total' => Format::number($info['cmd_set']),
                ],
            ],
            [
                'title' => 'flush',
                'data'  => [
                    'Total' => Format::number($info['cmd_flush']),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commandsStatsTab(): array {
        try {
            $info = $this->memcached->getServerStats();
            $commands = $this->commandsStatsData($info);
        } catch (MemcachedException $e) {
            $commands = ['error' => $e->getMessage()];
        }

        return ['commands' => $commands];
    }
}
