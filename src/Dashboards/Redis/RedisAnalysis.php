<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Dashboards\AnalysisTrait;
use RobiNN\Pca\Format;
use RobiNN\Pca\Http;

trait RedisAnalysis {
    use AnalysisTrait;

    /**
     * TTL buckets, in seconds. The last one catches everything above.
     *
     * @var array<string, int>
     */
    private array $ttl_buckets = [
        '< 1 minute' => 60,
        '< 1 hour'   => 3600,
        '< 1 day'    => 86400,
        '< 1 week'   => 604800,
        '1 week +'   => PHP_INT_MAX,
    ];

    private int $default_sample = 10_000;

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function analysisTab(): array {
        $sample_size = max(100, min((int) Http::get('sample', $this->default_sample), 500_000));

        $pattern = (string) Http::get('pattern', '*');
        $pattern = $pattern !== '' ? $pattern : '*';

        $depth = max(1, min((int) Http::get('depth', 1), 5));

        $data = [
            'sample_size' => $sample_size,
            'pattern'     => $pattern,
            'depth'       => $depth,
            'total_keys'  => $this->redis->databaseSize(),
            'analysis'    => null,
        ];

        if (Http::get('analyze') === null) {
            return $data;
        }

        $keys = $this->redis->scanKeys($pattern, $sample_size);

        if ($keys === []) {
            return $data + ['tab_message' => 'No keys matched the pattern.'];
        }

        $data['analysis'] = $this->analyzeKeys($keys, $this->pipeline($keys), $depth, $data['total_keys']);

        return $data;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<int, array<string, string>>
     */
    private function summaryTiles(array $summary, int $total_keys, int $depth): array {
        $share = $total_keys > 0 ? ($summary['scanned'] / $total_keys) * 100 : 0;

        return [
            [
                'label' => 'Keys scanned',
                'value' => Format::number($summary['scanned']),
                'note'  => Format::number($share, 2).'% of '.Format::number($total_keys).' keys in the database',
            ],
            [
                'label' => 'Memory in sample',
                'value' => Format::bytes($summary['memory']),
                'note'  => Format::bytes($summary['avg_memory']).' per key on average',
            ],
            [
                'label' => 'Namespaces',
                'value' => Format::number($summary['namespaces']),
                'note'  => 'at depth '.$depth,
            ],
            [
                'label' => 'Keys without TTL',
                'value' => Format::number($summary['no_expiry']['count']),
                'note'  => Format::bytes($summary['no_expiry']['memory']).', never expire on their own',
            ],
        ];
    }

    /**
     * @param array<int, string>       $keys
     * @param array<int|string, mixed> $pipeline
     *
     * @return array<string, mixed>
     */
    public function analyzeKeys(array $keys, array $pipeline, int $depth, int $total_keys): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        $scanned = 0;
        $total_memory = 0;
        $memory_reported = false;
        $no_expiry = ['count' => 0, 'memory' => 0];
        $types = [];
        $namespaces = [];
        $expiry = array_fill_keys(array_keys($this->ttl_buckets), 0);
        $by_memory = [];
        $by_length = [];

        foreach ($keys as $key) {
            // A key can expire between the SCAN and the pipeline call.
            if (!isset($pipeline[$key])) {
                continue;
            }

            $info = $pipeline[$key];
            $size = (int) ($info['size'] ?? 0);
            $type = (string) ($info['type'] ?? 'unknown');
            $ttl = (int) ($info['ttl'] ?? -1);
            $items = $info['count'] ?? null;

            $scanned++;
            $total_memory += $size;
            $memory_reported = $memory_reported || $size > 0;

            $types[$type]['count'] = ($types[$type]['count'] ?? 0) + 1;
            $types[$type]['memory'] = ($types[$type]['memory'] ?? 0) + $size;

            $namespace = $this->namespaceOf($key, $separator, $depth);
            $namespaces[$namespace]['count'] = ($namespaces[$namespace]['count'] ?? 0) + 1;
            $namespaces[$namespace]['memory'] = ($namespaces[$namespace]['memory'] ?? 0) + $size;

            if ($ttl < 0) {
                $no_expiry['count']++;
                $no_expiry['memory'] += $size;
            } else {
                $expiry[$this->bucket($ttl, $this->ttl_buckets)]++;
            }

            $this->collectTop($by_memory, ['key' => $key, 'type' => $type, 'size' => $size], 'size');

            if ($items !== null) {
                $this->collectTop($by_length, ['key' => $key, 'type' => $type, 'size' => $size, 'items' => (int) $items], 'items');
            }
        }

        if ($scanned === 0) {
            return [];
        }

        $summary = [
            'scanned'    => $scanned,
            'memory'     => $total_memory,
            'avg_memory' => (int) round($total_memory / $scanned),
            'no_expiry'  => $no_expiry,
            'namespaces' => count($namespaces),
        ];

        return [
            'summary'         => $summary,
            'tiles'           => $this->summaryTiles($summary, $total_keys, $depth),
            'memory_reported' => $memory_reported,
            'namespaces'      => $this->topGroups($namespaces, 'memory', $total_memory, $this->top_items),
            'types'           => $this->topGroups($types, 'memory', $total_memory),
            'expiry'          => $this->distribution(['No expiry' => $no_expiry['count']] + $expiry, $scanned),
            'top_memory'      => $this->topRows($by_memory, 'size', $this->top_items),
            'top_length'      => $this->topRows($by_length, 'items', $this->top_items),
        ];
    }
}
