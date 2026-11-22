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
     * Requests per second since the previous sample.
     *
     * @param array<string, int>        $counts
     * @param array<string, mixed>|null $previous The previous sample: time, uptime, counts and the rates computed then.
     *
     * @return array{0: array<string, float>|null, 1: bool} The rates (null without anything to compare with)
     *                                                       and whether this sample becomes the next baseline.
     */
    public function ratesSince(array $counts, int $uptime, ?array $previous, float $now): array {
        // A restart resets the counters, the difference would be wrong.
        if ($previous === null || $uptime < (int) ($previous['uptime'] ?? PHP_INT_MAX)) {
            return [null, true];
        }

        $elapsed = $now - (float) ($previous['time'] ?? 0);

        // E.g. the panels refresh right after the page has loaded, too soon to measure, the last rates still hold.
        if ($elapsed < 1) {
            $rates = $previous['rates'] ?? null;

            return [is_array($rates) ? array_map(floatval(...), $rates) : null, false];
        }

        $rates = [];

        foreach ($counts as $command => $count) {
            $rates[$command] = max(0, $count - (int) ($previous['counts'][$command] ?? $count)) / $elapsed;
        }

        return [$rates, true];
    }

    /**
     * @param array<string, int>        $counts
     * @param array<string, float>|null $rates See ratesSince().
     *
     * @return array<string, string> command => e.g. "12,5/s now, 3,1/s average"
     */
    public function rateLabels(array $counts, int $uptime, ?array $rates): array {
        $labels = [];

        foreach ($counts as $command => $count) {
            $average = Format::number($uptime > 0 ? $count / $uptime : 0, 2).'/s average';
            $labels[$command] = isset($rates[$command]) ? Format::number($rates[$command], 2).'/s now, '.$average : $average;
        }

        return $labels;
    }

    /**
     * @param array<int|string, mixed> $info
     * @param array<string, string>    $rates Requests per second by command, see rateLabels().
     *
     * @return array<int|string, mixed>
     */
    public function commandsStatsData(array $info, array $rates = []): array {
        $panels = $this->commandsPanels($info);

        foreach ($panels as &$panel) {
            if (isset($rates[$panel['title']])) {
                $panel['data']['Requests'] = $rates[$panel['title']];
            }
        }

        return $panels;
    }

    /**
     * @param array<int|string, mixed> $info
     *
     * @return array<int, array{title: string, data: array<int|string, mixed>}>
     */
    private function commandsPanels(array $info): array {
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
}
