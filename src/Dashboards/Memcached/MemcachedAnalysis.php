<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Dashboards\AnalysisTrait;
use RobiNN\Pca\Format;

trait MemcachedAnalysis {
    use AnalysisTrait;

    /**
     * Time left before a key expires, in seconds.
     *
     * @var array<string, int>
     */
    private array $exp_buckets = [
        '< 1 minute' => 60,
        '< 1 hour'   => 3600,
        '< 1 day'    => 86400,
        '< 1 week'   => 604800,
        '1 week +'   => PHP_INT_MAX,
    ];

    /**
     * Time since a key was last read, in seconds.
     *
     * @var array<string, int>
     */
    private array $idle_buckets = [
        '< 1 minute' => 60,
        '< 1 hour'   => 3600,
        '< 1 day'    => 86400,
        '< 1 week'   => 604800,
        '1 week +'   => PHP_INT_MAX,
    ];

    /**
     * Item size including the key and its overhead, as the metadump reports it.
     *
     * @var array<string, int>
     */
    private array $size_buckets = [
        '< 100 B'  => 100,
        '< 1 KB'   => 1024,
        '< 10 KB'  => 10_240,
        '< 100 KB' => 102_400,
        '< 1 MB'   => 1_048_576,
        '1 MB +'   => PHP_INT_MAX,
    ];

    /**
     * How many distinct flag values are listed, the rest are summed up.
     */
    private int $top_flags = 10;

    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function analysisTab(): array {
        $data = ['sizes' => $this->itemSizes()];
        $lines = $this->memcached->getKeys();

        if ($lines === []) {
            return $data + ['analysis' => null, 'tab_message' => 'There are no keys to analyze.'];
        }

        return $data + ['analysis' => $this->analyzeKeys($lines, $this->memcached->serverTime())];
    }

    /**
     * Item counts per size class, only with -o track_sizes.
     *
     * @return array<string, mixed>|null
     *
     * @throws MemcachedException
     */
    private function itemSizes(): ?array {
        if (version_compare($this->memcached->version(), '1.4.27', '<')) {
            return null;
        }

        return $this->sizeDistribution($this->memcached->getServerStats('sizes'));
    }

    /**
     * One line per size class, e.g. "STAT 96 12" for twelve items of up to 96 bytes.
     *
     * @param array<int|string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    public function sizeDistribution(array $stats): array {
        if (isset($stats['sizes_status']) && $stats['sizes_status'] !== 'enabled') {
            return ['enabled' => false, 'rows' => [], 'total' => 0];
        }

        $buckets = [];

        foreach ($stats as $size => $count) {
            if (is_numeric($size) && is_numeric($count)) {
                $buckets[(int) $size] = (int) $count;
            }
        }

        ksort($buckets);
        $total = array_sum($buckets);

        return [
            'enabled' => true,
            'rows'    => $this->distribution($buckets, $total),
            'total'   => $total,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<int, array<string, string>>
     */
    private function summaryTiles(array $summary): array {
        return [
            [
                'label' => 'Keys analyzed',
                'value' => Format::number($summary['analyzed']),
                'note'  => $summary['expired'] > 0 ? 'every live key, '.Format::number($summary['expired']).' expired skipped' : 'every key on the server',
            ],
            [
                'label' => 'Memory in keys',
                'value' => Format::bytes($summary['memory']),
                'note'  => Format::bytes($summary['avg_memory']).' per key on average',
            ],
            [
                'label' => 'Namespaces',
                'value' => Format::number($summary['namespaces']),
                'note'  => 'split on "'.$summary['separator'].'"',
            ],
            [
                'label' => 'Keys without expiry',
                'value' => Format::number($summary['no_expiry']['count']),
                'note'  => Format::bytes($summary['no_expiry']['memory']).', evicted only when memory runs out',
            ],
            [
                'label' => 'Never read',
                'value' => Format::number($summary['never_read']['count']),
                'note'  => Format::bytes($summary['never_read']['memory']).' stored but not fetched since',
            ],
        ];
    }

    /**
     * @param array<int, string> $lines
     * @param int|null           $time The server clock, metadump times are absolute timestamps of it.
     *
     * @return array<string, mixed>
     */
    public function analyzeKeys(array $lines, ?int $time = null): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        $time ??= time();

        $this->resetTopThresholds();

        $analyzed = 0;
        $total_memory = 0;
        $no_expiry = ['count' => 0, 'memory' => 0];
        $never_read = ['count' => 0, 'memory' => 0];
        $expired = 0;
        $namespaces = [];
        $expiry = array_fill_keys(array_keys($this->exp_buckets), 0);
        $idle = array_fill_keys(array_keys($this->idle_buckets), 0);
        $sizes = array_fill_keys(array_keys($this->size_buckets), ['count' => 0, 'memory' => 0]);
        $flags = [];
        $classes = [];
        $by_memory = [];

        foreach ($lines as $line) {
            $data = $this->memcached->parseLine($line);

            if (!isset($data['key'])) {
                continue;
            }

            // metadump returns key names urlencoded, decode them so both the grouping and the links use the real name
            $key = urldecode((string) $data['key']);
            $size = (int) ($data['size'] ?? 0);
            $exp = (int) ($data['exp'] ?? -1);
            $last_access = (int) ($data['la'] ?? 0);

            // Still in the dump until the server reclaims it, but no longer in the cache.
            if ($exp !== -1 && $exp <= $time) {
                $expired++;
                continue;
            }

            if (($data['fetch'] ?? true) === false) {
                $never_read['count']++;
                $never_read['memory'] += $size;
            }

            $analyzed++;
            $total_memory += $size;

            $namespace = $this->namespaceOf($key, $separator);
            $namespaces[$namespace]['count'] = ($namespaces[$namespace]['count'] ?? 0) + 1;
            $namespaces[$namespace]['memory'] = ($namespaces[$namespace]['memory'] ?? 0) + $size;

            if ($exp === -1) {
                $no_expiry['count']++;
                $no_expiry['memory'] += $size;
            } else {
                $expiry[$this->bucket(max(0, $exp - $time), $this->exp_buckets)]++;
            }

            if ($last_access > 0) {
                $idle[$this->bucket(max(0, $time - $last_access), $this->idle_buckets)]++;
            }

            $size_bucket = $this->bucket($size, $this->size_buckets);
            $sizes[$size_bucket]['count']++;
            $sizes[$size_bucket]['memory'] += $size;

            // Both are in the metadump since 1.6, older servers leave these tables out.
            if (isset($data['flags'])) {
                $flags[(int) $data['flags']] = ($flags[(int) $data['flags']] ?? 0) + 1;
            }

            if (isset($data['cls'])) {
                $classes[(int) $data['cls']]['count'] = ($classes[(int) $data['cls']]['count'] ?? 0) + 1;
                $classes[(int) $data['cls']]['memory'] = ($classes[(int) $data['cls']]['memory'] ?? 0) + $size;
            }

            if ($this->canMakeTop('size', $size)) {
                $this->collectTop($by_memory, ['key' => $key, 'size' => $size], 'size');
            }
        }

        if ($analyzed === 0) {
            return [];
        }

        $summary = [
            'analyzed'   => $analyzed,
            'memory'     => $total_memory,
            'avg_memory' => (int) round($total_memory / $analyzed),
            'no_expiry'  => $no_expiry,
            'never_read' => $never_read,
            'expired'    => $expired,
            'namespaces' => count($namespaces),
            'separator'  => $separator,
        ];

        return [
            'summary'    => $summary,
            'tiles'      => $this->summaryTiles($summary),
            'namespaces'       => $this->topGroups($namespaces, 'memory', $total_memory, $this->top_items),
            'namespaces_count' => $this->topGroups($namespaces, 'count', $analyzed, $this->top_items),
            'expiry'           => $this->distribution(['No expiry' => $no_expiry['count']] + $expiry, $analyzed),
            'idle'             => $this->distribution($idle, $analyzed),
            'sizes'            => $this->groupRows($sizes, $analyzed),
            'flags'            => $this->flagRows($flags, $analyzed),
            'classes'          => $this->groupRows($this->sortedByKey($classes), $total_memory, 'memory'),
            'top_memory'       => $this->topRows($by_memory, 'size', $this->top_items),
        ];
    }

    /**
     * @param array<int, array<string, int>> $groups
     *
     * @return array<int, array<string, int>>
     */
    private function sortedByKey(array $groups): array {
        ksort($groups);

        return $groups;
    }

    /**
     * Groups in their own order, with each one's share of the total by a column.
     *
     * @param array<int|string, array<string, int>> $groups
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupRows(array $groups, int $total, string $column = 'count'): array {
        $rows = [];

        foreach ($groups as $name => $group) {
            $rows[] = ['name' => $name] + $group + ['percent' => $this->percent($group[$column], $total)];
        }

        return $rows;
    }

    /**
     * The most common flag values with what the PHP memcached extension means by them, the rest summed up.
     *
     * @param array<int, int> $flags flags => keys
     *
     * @return array<int, array<string, mixed>>
     */
    private function flagRows(array $flags, int $total): array {
        arsort($flags);

        $rows = [];
        $other = 0;

        foreach ($flags as $value => $count) {
            if (count($rows) >= $this->top_flags) {
                $other += $count;
                continue;
            }

            $notes = MemcachedFlags::describe($value);
            $rows[] = [
                'name'    => (string) $value,
                'meaning' => match (true) {
                    $value === 0 => 'no flags',
                    $notes !== [] => implode(', ', $notes),
                    default => 'set by the client library',
                },
                'count'   => $count,
                'percent' => $this->percent($count, $total),
            ];
        }

        if ($other > 0) {
            $rows[] = ['name' => 'other', 'meaning' => 'the remaining values', 'count' => $other, 'percent' => $this->percent($other, $total)];
        }

        return $rows;
    }
}
