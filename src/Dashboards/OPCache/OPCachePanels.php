<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Format;

trait OPCachePanels {
    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(): array {
        $status = opcache_get_status(false);

        if ($status === false) {
            return ['error' => 'OPcache is not available, it is either disabled (opcache.enable) or restricted (opcache.restrict_api).'];
        }

        $directives = opcache_get_configuration()['directives'];
        $stats = $status['opcache_statistics'];
        $memory = $status['memory_usage'];

        return [
            $this->extensionPanel($status, $stats),
            $this->memoryPanel($memory, $directives['opcache.memory_consumption']),
            $this->statsPanel($stats, $directives),
            $this->jitPanel($status),
            $this->internedStringsPanel($status),
        ];
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>
     */
    private function jitInfo(array $status): array {
        $jit = $status['jit'] ?? [];

        return is_array($jit) ? $jit : [];
    }

    /**
     * @param array<string, mixed> $status
     */
    private function jitEnabled(array $status): bool {
        $jit = $this->jitInfo($status);

        return isset($jit['enabled']) && ($jit['buffer_size'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $stats
     *
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function extensionPanel(array $status, array $stats): array {
        return [
            'title' => 'OPCache extension v'.phpversion('Zend OPcache'),
            'data'  => [
                'JIT'                 => $this->jitEnabled($status) ? 'Enabled' : 'Disabled',
                'Start time'          => Format::time($stats['start_time']),
                'Uptime'              => Format::seconds(time() - $stats['start_time'], false),
                'Last restart'        => Format::time($stats['last_restart_time']),
                'Cache full'          => $status['cache_full'] ? 'Yes' : 'No',
                'Restart pending'     => $status['restart_pending'] ? 'Yes' : 'No',
                'Restart in progress' => $status['restart_in_progress'] ? 'Yes' : 'No',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $memory
     *
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function memoryPanel(array $memory, int $total_memory): array {
        $usage = round((($memory['used_memory'] + $memory['wasted_memory']) / $total_memory) * 100, 2);
        $wasted = round($memory['current_wasted_percentage'], 2);

        return [
            'title' => 'Memory',
            'data'  => [
                'Total' => Format::bytes($total_memory, 0),
                ['Used', Format::bytes($memory['used_memory']).' ('.$usage.'%)', $usage],
                'Free'  => Format::bytes($memory['free_memory']),
                ['Wasted', Format::bytes($memory['wasted_memory']).' ('.$wasted.'%)', $wasted],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $directives
     *
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function statsPanel(array $stats, array $directives): array {
        $used_scripts = round(($stats['num_cached_scripts'] / (int) ini_get('opcache.max_accelerated_files')) * 100);
        $used_keys = round(($stats['num_cached_keys'] / $stats['max_cached_keys']) * 100);
        $hit_rate = round($stats['opcache_hit_rate'], 2);

        return [
            'title' => 'Stats',
            'data'  => [
                'Max accelerated_files' => Format::number($directives['opcache.max_accelerated_files']),
                ['Cached scripts', Format::number($stats['num_cached_scripts']).' ('.$used_scripts.'%)', $used_scripts],
                ['Cached keys', Format::number($stats['num_cached_keys']).' ('.$used_keys.'%)', $used_keys],
                'Max cached keys'       => Format::number($stats['max_cached_keys']),
                ['Hits / Misses', Format::number($stats['hits']).' / '.Format::number($stats['misses']).' (Rate '.$hit_rate.'%)', $hit_rate, 'higher'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<int|string, mixed>
     */
    private function jitPanel(array $status): array {
        if (!$this->jitEnabled($status)) {
            return [];
        }

        $jit = $this->jitInfo($status);
        $used = $jit['buffer_size'] - $jit['buffer_free'];
        $usage = round(($used / $jit['buffer_size']) * 100, 2);

        return [
            'title' => 'JIT',
            'data'  => [
                'Buffer size'        => Format::bytes($jit['buffer_size']),
                ['Used', Format::bytes($used).' ('.$usage.'%)', $usage],
                'Free'               => Format::bytes($jit['buffer_free']),
                'Optimization level' => $jit['opt_level'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function internedStringsPanel(array $status): array {
        $interned = $status['interned_strings_usage'] ?? null;

        if (!is_array($interned)) {
            $interned = ['buffer_size' => 0, 'used_memory' => 0, 'free_memory' => 0, 'number_of_strings' => 0];
        }

        $usage = ($interned['buffer_size'] ?? 0) > 0 ? round(($interned['used_memory'] / $interned['buffer_size']) * 100, 2) : 0;

        return [
            'title' => 'Interned strings usage',
            'data'  => [
                'Buffer size' => Format::bytes($interned['buffer_size']),
                ['Used', Format::bytes($interned['used_memory']).' ('.$usage.'%)', $usage],
                'Free'        => Format::bytes($interned['free_memory']),
                'Strings'     => Format::number($interned['number_of_strings']),
            ],
        ];
    }
}
