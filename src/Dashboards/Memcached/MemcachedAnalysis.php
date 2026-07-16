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
     * Time left before a key expires, in seconds. The last bucket catches everything above.
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
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function analysisTab(): array {
        $lines = $this->memcached->getKeys();

        if ($lines === []) {
            return ['analysis' => null, 'tab_message' => 'There are no keys to analyze.'];
        }

        return ['analysis' => $this->analyzeKeys($lines)];
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
                'note'  => 'every key on the server',
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
        ];
    }

    /**
     * @param array<int, string> $lines
     *
     * @return array<string, mixed>
     */
    public function analyzeKeys(array $lines): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        $time = time();

        $this->resetTopThresholds();

        $analyzed = 0;
        $total_memory = 0;
        $no_expiry = ['count' => 0, 'memory' => 0];
        $namespaces = [];
        $expiry = array_fill_keys(array_keys($this->exp_buckets), 0);
        $idle = array_fill_keys(array_keys($this->idle_buckets), 0);
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

            $this->collectTop($by_memory, ['key' => $key, 'size' => $size], 'size');
        }

        if ($analyzed === 0) {
            return [];
        }

        $summary = [
            'analyzed'   => $analyzed,
            'memory'     => $total_memory,
            'avg_memory' => (int) round($total_memory / $analyzed),
            'no_expiry'  => $no_expiry,
            'namespaces' => count($namespaces),
            'separator'  => $separator,
        ];

        return [
            'summary'    => $summary,
            'tiles'      => $this->summaryTiles($summary),
            'namespaces' => $this->topGroups($namespaces, 'memory', $total_memory, $this->top_items),
            'expiry'     => $this->distribution(['No expiry' => $no_expiry['count']] + $expiry, $analyzed),
            'idle'       => $this->distribution($idle, $analyzed),
            'top_memory' => $this->topRows($by_memory, 'size', $this->top_items),
        ];
    }
}
