<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use APCUIterator;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\AnalysisTrait;
use RobiNN\Pca\Format;

trait APCuAnalysis {
    use AnalysisTrait;

    /**
     * Time left before an entry expires, in seconds. The last bucket catches everything above.
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
     * Time since an entry was last read, in seconds.
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
     */
    private function analysisTab(): array {
        $fields = APC_ITER_KEY | APC_ITER_TTL | APC_ITER_MEM_SIZE | APC_ITER_NUM_HITS | APC_ITER_ATIME | APC_ITER_CTIME;
        $entries = [];

        foreach (new APCUIterator(null, $fields, 0, APC_LIST_ACTIVE) as $item) {
            $entries[] = $item;
        }

        if ($entries === []) {
            return ['analysis' => null, 'tab_message' => 'There are no keys to analyze.'];
        }

        return ['analysis' => $this->analyzeKeys($entries)];
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
                'note'  => 'every entry in the user cache',
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
                'note'  => Format::bytes($summary['no_expiry']['memory']).', kept until the cache is full',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return array<string, mixed>
     */
    public function analyzeKeys(array $entries): array {
        $separator = (string) Config::get('apcuseparator', ':');
        $time = time();

        $this->resetTopThresholds();

        $analyzed = 0;
        $total_memory = 0;
        $no_expiry = ['count' => 0, 'memory' => 0];
        $namespaces = [];
        $expiry = array_fill_keys(array_keys($this->exp_buckets), 0);
        $idle = array_fill_keys(array_keys($this->idle_buckets), 0);
        $by_memory = [];
        $by_hits = [];

        foreach ($entries as $item) {
            if (!isset($item['key'])) {
                continue;
            }

            $key = (string) $item['key'];
            $size = (int) ($item['mem_size'] ?? 0);
            $ttl = (int) ($item['ttl'] ?? 0);
            $created = (int) ($item['creation_time'] ?? 0);
            $last_access = (int) ($item['access_time'] ?? 0);

            $analyzed++;
            $total_memory += $size;

            $namespace = $this->namespaceOf($key, $separator);
            $namespaces[$namespace]['count'] = ($namespaces[$namespace]['count'] ?? 0) + 1;
            $namespaces[$namespace]['memory'] = ($namespaces[$namespace]['memory'] ?? 0) + $size;

            // A zero TTL never expires on its own, APCu only drops it when the cache runs out of memory.
            if ($ttl === 0) {
                $no_expiry['count']++;
                $no_expiry['memory'] += $size;
            } else {
                $expiry[$this->bucket(max(0, $created + $ttl - $time), $this->exp_buckets)]++;
            }

            if ($last_access > 0) {
                $idle[$this->bucket(max(0, $time - $last_access), $this->idle_buckets)]++;
            }

            $this->collectTop($by_memory, ['key' => $key, 'size' => $size], 'size');
            $this->collectTop($by_hits, ['key' => $key, 'hits' => (int) ($item['num_hits'] ?? 0), 'size' => $size], 'hits');
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
            'top_hits'   => $this->topRows($by_hits, 'hits', $this->top_items),
        ];
    }
}
