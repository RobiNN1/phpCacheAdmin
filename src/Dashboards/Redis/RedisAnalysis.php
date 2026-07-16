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

    private int $big_key_bytes = 1_048_576; // 1 MB

    private int $huge_key_bytes = 10_485_760; // 10 MB

    private int $long_collection_items = 5_000;

    private int $finding_examples = 5;

    /**
     * Worst first, so the recommendations read top down.
     *
     * @var array<string, int>
     */
    private array $status_order = ['critical' => 0, 'warning' => 1, 'info' => 2];

    /**
     * @return array<string, mixed>
     */
    private function analysisContext(): array {
        $context = ['memory' => $this->isCommandSupported('MEMORY')];

        try {
            $memory = $this->redis->getInfo('memory');

            // In a cluster these come back per node and only collapse when every node agrees.
            if (is_numeric($memory['maxmemory'] ?? null)) {
                $context['maxmemory'] = (int) $memory['maxmemory'];
            }

            if (is_string($memory['maxmemory_policy'] ?? null)) {
                $context['maxmemory_policy'] = $memory['maxmemory_policy'];
            }
        } catch (Exception) {
            // INFO is unavailable.
        }

        // Redis < 7 calls it ziplist, 7 keeps that name as an alias.
        foreach (['hash-max-listpack-entries', 'hash-max-ziplist-entries'] as $directive) {
            try {
                $config = $this->redis->execConfig('GET', $directive);
            } catch (Exception) {
                break; // CONFIG is disabled.
            }

            if (is_array($config) && is_numeric($config[$directive] ?? null)) {
                $context['hash_directive'] = $directive;
                $context['hash_limit'] = (int) $config[$directive];
                break;
            }
        }

        return $context;
    }

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

        $data['analysis'] = $this->analyzeKeys($keys, $this->pipeline($keys), $depth, $data['total_keys'], $this->analysisContext());

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, string>>
     */
    private function findingExamples(array $rows, string $column, int $threshold, callable $note): array {
        $examples = [];

        foreach ($rows as $row) {
            if ($row[$column] < $threshold || count($examples) >= $this->finding_examples) {
                break;
            }

            $examples[] = ['key' => $row['key'], 'note' => $note($row)];
        }

        return $examples;
    }

    /**
     * @param array<string, mixed>             $finding
     * @param array<int, array<string, mixed>> $top_memory
     *
     * @return array<string, mixed>|null
     */
    private function bigKeysRecommendation(array $finding, array $top_memory): ?array {
        if ($finding['count'] === 0) {
            return null;
        }

        return [
            'name'       => 'Big keys',
            'status'     => $finding['largest'] >= $this->huge_key_bytes ? 'critical' : 'warning',
            'directive'  => '',
            'detail'     => sprintf(
                '%s of the sampled keys are %s or bigger, the largest is %s.',
                Format::number($finding['count']),
                Format::bytes($this->big_key_bytes),
                Format::bytes($finding['largest'])
            ),
            'suggestion' => 'Every command that touches a big key is slow, and deleting or expiring one blocks the server while it frees the memory. Split them into smaller keys, or keep the payload elsewhere and store only a reference.',
            'keys'       => $this->findingExamples($top_memory, 'size', $this->big_key_bytes, static fn (array $row): string => Format::bytes($row['size'])),
        ];
    }

    /**
     * @param array<string, mixed>             $finding
     * @param array<int, array<string, mixed>> $top_length
     *
     * @return array<string, mixed>|null
     */
    private function longCollectionsRecommendation(array $finding, array $top_length): ?array {
        if ($finding['count'] === 0) {
            return null;
        }

        return [
            'name'       => 'Long collections',
            'status'     => 'warning',
            'directive'  => '',
            'detail'     => sprintf(
                '%s of the sampled keys hold %s items or more, the longest holds %s.',
                Format::number($finding['count']),
                Format::number($this->long_collection_items),
                Format::number($finding['longest'])
            ),
            'suggestion' => 'Commands that walk a whole collection (HGETALL, SMEMBERS, LRANGE 0 -1) are O(N) and block the server for the whole walk. Read them with the SCAN family (HSCAN, SSCAN, ZSCAN) instead, or shard the key.',
            'keys'       => $this->findingExamples($top_length, 'items', $this->long_collection_items, static fn (array $row): string => Format::number($row['items']).' items'),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function noExpiryRecommendation(array $summary, array $context, bool $has_memory): ?array {
        $share = $this->percent($summary['no_expiry']['count'], $summary['scanned']);

        if ($share < 50.0 || !isset($context['maxmemory'])) {
            return null;
        }

        $policy = (string) ($context['maxmemory_policy'] ?? '');
        $counts = Format::number($summary['no_expiry']['count']).($has_memory ? ', '.Format::bytes($summary['no_expiry']['memory']) : '');
        $detail = sprintf('%s%% of the sampled keys (%s) never expire.', Format::number($share, 2), $counts);

        // volatile-* may only evict keys that have a TTL, so without one there is nothing to reclaim.
        if ($context['maxmemory'] > 0 && str_starts_with($policy, 'volatile-')) {
            return [
                'name'       => 'Keys without a TTL',
                'status'     => 'critical',
                'directive'  => 'maxmemory-policy',
                'detail'     => $detail.' The policy is "'.$policy.'", which may only evict keys that have one.',
                'suggestion' => 'Once maxmemory is reached Redis will have almost nothing it is allowed to evict, and writes will start failing even though most of the memory is reclaimable. Give these keys a TTL, or switch to an allkeys-* policy.',
                'keys'       => [],
            ];
        }

        if ($context['maxmemory'] <= 0) {
            return [
                'name'       => 'Keys without a TTL',
                'status'     => 'warning',
                'directive'  => 'maxmemory',
                'detail'     => $detail.' No memory limit is set.',
                'suggestion' => 'Nothing bounds the keyspace, so it grows until the machine runs out of memory and Redis is killed. Set maxmemory together with a policy, or give the keys a TTL.',
                'keys'       => [],
            ];
        }

        if ($policy === 'noeviction') {
            return [
                'name'       => 'Keys without a TTL',
                'status'     => 'warning',
                'directive'  => 'maxmemory-policy',
                'detail'     => $detail.' The policy is "noeviction".',
                'suggestion' => 'Nothing will be removed to make room, so writes fail once maxmemory is reached. Give the keys a TTL, or pick an eviction policy.',
                'keys'       => [],
            ];
        }

        return null; // an allkeys-* policy with a limit set reclaims these on its own
    }

    /**
     * @param array<string, mixed> $finding
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function hashEncodingRecommendation(array $finding, array $context): ?array {
        if ($finding['count'] === 0 || !isset($context['hash_limit'], $context['hash_directive'])) {
            return null;
        }

        return [
            'name'       => 'Hashes past the listpack limit',
            'status'     => 'info',
            'directive'  => $context['hash_directive'].' = '.Format::number($context['hash_limit']),
            'detail'     => sprintf(
                '%s of the sampled hashes hold more than %s fields.',
                Format::number($finding['count']),
                Format::number($context['hash_limit'])
            ),
            'suggestion' => 'Past that many fields a hash drops the compact listpack encoding for a hashtable, which costs several times more memory per field. Raising the limit trades CPU for memory, splitting the hash keeps both down.',
            'keys'       => $finding['keys'],
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $findings
     * @param array<string, mixed> $context
     *
     * @return array<int, array<string, mixed>>
     */
    private function recommendations(array $analysis, array $findings, array $context): array {
        $recommendations = array_values(array_filter([
            // Without MEMORY USAGE there is no size to call a key big by.
            $analysis['memory'] ? $this->bigKeysRecommendation($findings['big_keys'], $analysis['top_memory']) : null,
            $this->longCollectionsRecommendation($findings['long_collections'], $analysis['top_length']),
            $this->noExpiryRecommendation($analysis['summary'], $context, $analysis['memory']),
            $this->hashEncodingRecommendation($findings['big_hashes'], $context),
        ]));

        usort($recommendations, fn (array $a, array $b): int => ($this->status_order[$a['status']] ?? PHP_INT_MAX) <=> ($this->status_order[$b['status']] ?? PHP_INT_MAX));

        return $recommendations;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<int, array<string, string>>
     */
    private function summaryTiles(array $summary, int $total_keys, int $depth, bool $has_memory): array {
        $share = $total_keys > 0 ? ($summary['scanned'] / $total_keys) * 100 : 0;

        $tiles = [
            [
                'label' => 'Keys scanned',
                'value' => Format::number($summary['scanned']),
                'note'  => Format::number($share, 2).'% of '.Format::number($total_keys).' keys in the database',
            ],
        ];

        if ($has_memory) {
            $tiles[] = [
                'label' => 'Memory in sample',
                'value' => Format::bytes($summary['memory']),
                'note'  => Format::bytes($summary['avg_memory']).' per key on average',
            ];
        }

        $tiles[] = [
            'label' => 'Namespaces',
            'value' => Format::number($summary['namespaces']),
            'note'  => 'at depth '.$depth,
        ];

        $tiles[] = [
            'label' => 'Keys without TTL',
            'value' => Format::number($summary['no_expiry']['count']),
            'note'  => ($has_memory ? Format::bytes($summary['no_expiry']['memory']).', never' : 'never').' expire on their own',
        ];

        return $tiles;
    }

    /**
     * @param array<int, string>       $keys
     * @param array<int|string, mixed> $pipeline
     * @param array<string, mixed>     $context Server settings for the recommendations, see analysisContext().
     *
     * @return array<string, mixed>
     */
    public function analyzeKeys(array $keys, array $pipeline, int $depth, int $total_keys, array $context = []): array {
        $options = [
            'separator'  => $this->servers[$this->current_server]['separator'] ?? ':',
            'depth'      => $depth,
            'hash_limit' => $context['hash_limit'] ?? null,
        ];

        $this->resetTopThresholds();
        $stats = $this->emptyStats();

        foreach ($keys as $key) {
            // A key can expire between the SCAN and the pipeline call.
            if (isset($pipeline[$key])) {
                $this->collectKey($stats, $key, $pipeline[$key], $options);
            }
        }

        return $stats['scanned'] > 0 ? $this->analysisResult($stats, $depth, $total_keys, $context) : [];
    }

    /**
     * Everything the loop tallies into, in one place.
     *
     * @return array<string, mixed>
     */
    private function emptyStats(): array {
        return [
            'scanned'         => 0,
            'memory'          => 0,
            'memory_reported' => false,
            'no_expiry'       => ['count' => 0, 'memory' => 0],
            'types'           => [],
            'namespaces'      => [],
            'expiry'          => array_fill_keys(array_keys($this->ttl_buckets), 0),
            'by_memory'       => [],
            'by_length'       => [],
            'findings'        => [
                'big_keys'         => ['count' => 0, 'largest' => 0],
                'long_collections' => ['count' => 0, 'longest' => 0],
                'big_hashes'       => ['count' => 0, 'keys' => []],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $info
     * @param array<string, mixed> $options
     */
    private function collectKey(array &$stats, string $key, array $info, array $options): void {
        $size = (int) ($info['size'] ?? 0);
        $type = (string) ($info['type'] ?? 'unknown');
        $ttl = (int) ($info['ttl'] ?? -1);
        // Strings have no item count, only collections do.
        $items = isset($info['count']) ? (int) $info['count'] : null;

        $stats['scanned']++;
        $stats['memory'] += $size;
        $stats['memory_reported'] = $stats['memory_reported'] || $size > 0;

        $stats['types'][$type]['count'] = ($stats['types'][$type]['count'] ?? 0) + 1;
        $stats['types'][$type]['memory'] = ($stats['types'][$type]['memory'] ?? 0) + $size;

        $namespace = $this->namespaceOf($key, $options['separator'], $options['depth']);
        $stats['namespaces'][$namespace]['count'] = ($stats['namespaces'][$namespace]['count'] ?? 0) + 1;
        $stats['namespaces'][$namespace]['memory'] = ($stats['namespaces'][$namespace]['memory'] ?? 0) + $size;

        if ($ttl < 0) {
            $stats['no_expiry']['count']++;
            $stats['no_expiry']['memory'] += $size;
        } else {
            $stats['expiry'][$this->bucket($ttl, $this->ttl_buckets)]++;
        }

        $row = ['key' => $key, 'type' => $type, 'size' => $size, 'items' => $items];

        $this->collectTop($stats['by_memory'], $row, 'size');

        if ($items !== null) {
            $this->collectTop($stats['by_length'], $row, 'items');
        }

        $this->collectFindings($stats['findings'], $row, $options['hash_limit']);
    }

    /**
     * @param array<string, mixed> $findings
     * @param array<string, mixed> $row
     */
    private function collectFindings(array &$findings, array $row, ?int $hash_limit): void {
        if ($row['size'] >= $this->big_key_bytes) {
            $findings['big_keys']['count']++;
            $findings['big_keys']['largest'] = max($findings['big_keys']['largest'], $row['size']);
        }

        if ($row['items'] === null) {
            return;
        }

        if ($row['items'] >= $this->long_collection_items) {
            $findings['long_collections']['count']++;
            $findings['long_collections']['longest'] = max($findings['long_collections']['longest'], $row['items']);
        }

        if ($hash_limit !== null && $row['type'] === 'hash' && $row['items'] > $hash_limit) {
            $findings['big_hashes']['count']++;

            if (count($findings['big_hashes']['keys']) < $this->finding_examples) {
                $findings['big_hashes']['keys'][] = ['key' => $row['key'], 'note' => Format::number($row['items']).' fields'];
            }
        }
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function analysisResult(array $stats, int $depth, int $total_keys, array $context): array {
        $summary = [
            'scanned'    => $stats['scanned'],
            'memory'     => $stats['memory'],
            'avg_memory' => (int) round($stats['memory'] / $stats['scanned']),
            'no_expiry'  => $stats['no_expiry'],
            'namespaces' => count($stats['namespaces']),
        ];

        // Without MEMORY USAGE every size is 0, so rank and weigh everything by key count instead of showing zeros.
        $has_memory = ($context['memory'] ?? true) && $stats['memory_reported'];
        $column = $has_memory ? 'memory' : 'count';
        $total = $has_memory ? $stats['memory'] : $stats['scanned'];

        $analysis = [
            'summary'    => $summary,
            'memory'     => $has_memory,
            'tiles'      => $this->summaryTiles($summary, $total_keys, $depth, $has_memory),
            'namespaces' => $this->topGroups($stats['namespaces'], $column, $total, $this->top_items),
            'types'      => $this->topGroups($stats['types'], $column, $total),
            'expiry'     => $this->distribution(['No expiry' => $stats['no_expiry']['count']] + $stats['expiry'], $stats['scanned']),
            'top_memory' => $has_memory ? $this->topRows($stats['by_memory'], 'size', $this->top_items) : [],
            'top_length' => $this->topRows($stats['by_length'], 'items', $this->top_items),
        ];

        // The top lists double as the examples, so this has to run once they are sorted.
        $analysis['recommendations'] = $this->recommendations($analysis, $stats['findings'], $context);

        return $analysis;
    }
}
