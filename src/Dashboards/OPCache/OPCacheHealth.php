<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;

trait OPCacheHealth {
    /**
     * @param array<string, mixed>|null $status
     * @param array<string, mixed>|null $directives
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHealthChecks(?array $status = null, ?array $directives = null): array {
        $status ??= @opcache_get_status(false) ?: null;

        if ($status === null || !isset($status['memory_usage'], $status['opcache_statistics'])) {
            return [];
        }

        $directives ??= opcache_get_configuration()['directives'];
        $memory = $status['memory_usage'];
        $stats = $status['opcache_statistics'];

        $checks = [
            $this->memoryCheck($memory, $stats, $directives),
            $this->keysCheck($stats),
            $this->internedStringsCheck($status),
            $this->jitCheck($status),
            $this->wastedMemoryCheck($memory, $directives),
            $this->hitRateCheck($stats),
            $this->fileCacheCheck($directives),
        ];

        return array_values(array_filter($checks, static fn (?array $check): bool => $check !== null));
    }

    /**
     * @param array<string, mixed> $memory
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $directives
     *
     * @return array<string, mixed>
     */
    private function memoryCheck(array $memory, array $stats, array $directives): array {
        $total = $this->totalMemory($memory, $directives);
        $used = $memory['used_memory'] + $memory['wasted_memory'];
        $utilization = $total > 0 ? ($used / $total) * 100 : 0;
        $status = $stats['oom_restarts'] > 0 ? 'critical' : Helpers::utilizationStatus($utilization);
        $suggestion = '';

        if ($status !== 'healthy') {
            $suggestion = 'Increase opcache.memory_consumption.';

            if ($stats['oom_restarts'] > 0) {
                $suggestion .= ' Out-of-memory restarts detected ('.$stats['oom_restarts'].').';
            }

            if ($memory['wasted_memory'] > 0) {
                $suggestion .= ' Wasted: '.Format::bytes($memory['wasted_memory']).'.';
            }
        }

        return [
            'name'        => 'Memory usage',
            'directive'   => 'opcache.memory_consumption',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => Format::bytes($memory['used_memory']).' of '.Format::bytes($total).' used',
            'suggestion'  => $suggestion,
        ];
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    private function keysCheck(array $stats): array {
        $utilization = $stats['max_cached_keys'] > 0 ? ($stats['num_cached_keys'] / $stats['max_cached_keys']) * 100 : 0;
        $status = $stats['hash_restarts'] > 0 ? 'critical' : Helpers::utilizationStatus($utilization);
        $suggestion = '';

        if ($status !== 'healthy') {
            $suggestion = 'Increase opcache.max_accelerated_files.';

            if ($stats['hash_restarts'] > 0) {
                $suggestion .= ' Hash restarts detected ('.$stats['hash_restarts'].').';
            }
        }

        return [
            'name'        => 'Key usage',
            'directive'   => 'opcache.max_accelerated_files',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => Format::number($stats['num_cached_keys']).' of '.Format::number($stats['max_cached_keys']).' keys used',
            'suggestion'  => $suggestion,
        ];
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>|null
     */
    private function internedStringsCheck(array $status): ?array {
        $interned = $status['interned_strings_usage'] ?? null;

        if (!is_array($interned) || ($interned['buffer_size'] ?? 0) <= 0) {
            return null;
        }

        $utilization = ($interned['used_memory'] / $interned['buffer_size']) * 100;
        $status_color = Helpers::utilizationStatus($utilization);

        return [
            'name'        => 'Interned strings',
            'directive'   => 'opcache.interned_strings_buffer',
            'utilization' => round($utilization, 2),
            'status'      => $status_color,
            'detail'      => Format::bytes($interned['used_memory']).' of '.Format::bytes($interned['buffer_size']).' used',
            'suggestion'  => $status_color !== 'healthy' ? 'Increase opcache.interned_strings_buffer.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>|null
     */
    private function jitCheck(array $status): ?array {
        $jit = $status['jit'] ?? null;

        if (!is_array($jit) || !isset($jit['buffer_size'])) {
            return null;
        }

        if ($jit['buffer_size'] <= 0) {
            return [
                'name'        => 'JIT buffer',
                'directive'   => 'opcache.jit_buffer_size',
                'utilization' => 0,
                'status'      => 'info',
                'detail'      => 'JIT is disabled.',
                'suggestion'  => '',
            ];
        }

        $used = $jit['buffer_size'] - $jit['buffer_free'];
        $utilization = ($used / $jit['buffer_size']) * 100;
        $status_color = Helpers::utilizationStatus($utilization);

        return [
            'name'        => 'JIT buffer',
            'directive'   => 'opcache.jit_buffer_size',
            'utilization' => round($utilization, 2),
            'status'      => $status_color,
            'detail'      => Format::bytes($used).' of '.Format::bytes($jit['buffer_size']).' used',
            'suggestion'  => $status_color !== 'healthy' ? 'Increase opcache.jit_buffer_size.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $memory
     * @param array<string, mixed> $directives
     *
     * @return array<string, mixed>
     */
    private function wastedMemoryCheck(array $memory, array $directives): array {
        $max_wasted = (float) $directives['opcache.max_wasted_percentage'] * 100;
        $current_wasted = $memory['current_wasted_percentage'];

        if ($max_wasted <= 0) {
            return [
                'name'        => 'Wasted memory',
                'directive'   => 'opcache.max_wasted_percentage',
                'utilization' => 0,
                'status'      => 'healthy',
                'detail'      => Format::bytes($memory['wasted_memory']).' wasted, auto-reset threshold disabled',
                'suggestion'  => '',
            ];
        }

        if ($current_wasted >= $max_wasted) {
            $status = 'critical';
            $suggestion = 'Wasted memory has reached the auto-reset threshold. When the cache fills up, OPcache will reset automatically to reclaim this orphaned space. High waste usually means scripts are frequently invalidated or recompiled, check your deploy process and whether opcache.validate_timestamps is enabled in production.';
        } elseif ($current_wasted >= $max_wasted * 0.5) {
            $status = 'warning';
            $suggestion = 'Wasted memory is approaching the auto-reset threshold ('.Format::number($max_wasted, 1).'%).';
        } else {
            $status = 'healthy';
            $suggestion = '';
        }

        return [
            'name'        => 'Wasted memory',
            'directive'   => 'opcache.max_wasted_percentage',
            'utilization' => round(min(($current_wasted / $max_wasted) * 100, 100), 2),
            'status'      => $status,
            'detail'      => Format::bytes($memory['wasted_memory']).' wasted, auto-reset triggers at '.Format::number($max_wasted, 1).'%',
            'suggestion'  => $suggestion,
        ];
    }

    /**
     * @param array<string, mixed> $stats
     *
     * @return array<string, mixed>
     */
    private function hitRateCheck(array $stats): array {
        $hit_rate = $stats['opcache_hit_rate'];
        $status = Helpers::hitRateStatus($hit_rate);

        return [
            'name'        => 'Hit rate',
            'directive'   => '',
            'utilization' => round($hit_rate, 2),
            'status'      => $status,
            'detail'      => Format::number($hit_rate, 2).'% cache hit rate',
            'suggestion'  => $status !== 'healthy' ? 'A low hit rate can be normal on a low-traffic server or shortly after a restart. Otherwise scripts may be getting evicted, check opcache.max_accelerated_files and opcache.memory_consumption.' : '',
        ];
    }

    /**
     * @param array<string, mixed> $directives
     *
     * @return array<string, mixed>|null
     */
    private function fileCacheCheck(array $directives): ?array {
        $file_cache = (string) $directives['opcache.file_cache'];

        if ($file_cache === '') {
            return null;
        }

        if (!empty($directives['opcache.file_cache_only'])) {
            $file_cache .= ' (file_cache_only)';
        }

        return [
            'name'        => 'File cache',
            'directive'   => 'opcache.file_cache',
            'utilization' => 0,
            'status'      => 'info',
            'detail'      => $file_cache,
            'suggestion'  => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthTab(): array {
        $status = @opcache_get_status(false);

        if ($status === false) {
            return ['tab_error' => self::NOT_AVAILABLE];
        }

        if (!isset($status['memory_usage'], $status['opcache_statistics'])) {
            return ['tab_error' => self::NO_SHARED_MEMORY];
        }

        return ['checks' => $this->getHealthChecks()];
    }
}
