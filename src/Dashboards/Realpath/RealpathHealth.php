<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Realpath;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;

trait RealpathHealth {
    /**
     * Anything at or below the PHP default expires often enough to be worth pointing out.
     */
    private int $default_ttl = 120;

    /**
     * @param array<string, mixed>|null $info
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHealthChecks(?array $info = null): array {
        $info ??= [
            'used'          => realpath_cache_size(),
            'total'         => Format::iniSizeToBytes((string) ini_get('realpath_cache_size')),
            'ttl'           => (int) ini_get('realpath_cache_ttl'),
            'open_basedir'  => (string) ini_get('open_basedir'),
            'entries'       => realpath_cache_get(),
        ];

        return [
            $this->memoryCheck($info),
            $this->entriesCheck($info),
            $this->ttlCheck($info),
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function memoryCheck(array $info): array {
        $check = ['name' => 'Memory usage', 'directive' => 'realpath_cache_size', 'utilization' => 0];

        // open_basedir sets realpath_cache_size to 0, so there is no cache to measure.
        if ((string) $info['open_basedir'] !== '') {
            return $check + [
                'status'     => 'info',
                'detail'     => 'The cache is disabled because open_basedir is set',
                'suggestion' => 'PHP resolves every path again on each request, which costs extra stat() calls. That is the price of the open_basedir restriction, not something to fix here.',
            ];
        }

        $used = (int) $info['used'];
        $total = (int) $info['total'];

        if ($total <= 0) {
            return $check + [
                'status'     => 'info',
                'detail'     => Format::bytes($used).' used, no cache size is set',
                'suggestion' => '',
            ];
        }

        $utilization = ($used / $total) * 100;
        $status = Helpers::utilizationStatus($utilization);

        return [
            'name'        => 'Memory usage',
            'directive'   => 'realpath_cache_size',
            'utilization' => round($utilization, 2),
            'status'      => $status,
            'detail'      => Format::bytes($used).' of '.Format::bytes($total).' used',
            'suggestion'  => $status !== 'healthy'
                ? 'Once the cache is full, PHP stops caching new paths and stats them on every request instead. Raise realpath_cache_size.'
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function entriesCheck(array $info): array {
        $entries = (array) $info['entries'];
        $time = time();
        $expired = 0;

        foreach ($entries as $entry) {
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) <= $time) {
                $expired++;
            }
        }

        $count = count($entries);
        $share = $count > 0 ? ($expired / $count) * 100 : 0;

        return [
            'name'        => 'Entries',
            'directive'   => '',
            'utilization' => round($share, 2),
            'status'      => $share > 50 ? 'warning' : 'healthy',
            'detail'      => Format::number($count).' cached paths, '.Format::number($expired).' of them expired',
            'suggestion'  => $share > 50
                ? 'Most of the cache has expired, so these paths will be resolved again the next time they are used. A longer realpath_cache_ttl keeps them for longer.'
                : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<string, mixed>
     */
    private function ttlCheck(array $info): array {
        $ttl = (int) $info['ttl'];

        return [
            'name'        => 'TTL',
            'directive'   => 'realpath_cache_ttl',
            'utilization' => 0,
            'status'      => $ttl <= $this->default_ttl ? 'info' : 'healthy',
            'detail'      => 'Paths are kept for '.Format::seconds($ttl, false),
            'suggestion'  => $ttl <= $this->default_ttl
                ? 'This is the PHP default. On a server whose files only change on deploy, a higher realpath_cache_ttl saves the same lookups being repeated every couple of minutes.'
                : '',
        ];
    }
}
