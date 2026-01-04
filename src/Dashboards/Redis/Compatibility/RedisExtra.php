<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Exception;

trait RedisExtra {
    /**
     * @return array<int, string>
     */
    public function getInfoSections(): array {
        return [
            'server', 'clients', 'memory', 'persistence', 'threads', 'stats', 'replication', 'cpu',
            'commandstats', 'latencystats', 'cluster', 'keyspace', 'errorstats',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseSectionData(string $section): array {
        $data = $this->getInfo($section);

        return array_map(static function ($value) {
            if (is_array($value)) {
                return $value;
            }

            parse_str(str_replace(',', '&', $value), $parsed);

            return $parsed;
        }, $data);
    }

    /**
     * @throws Exception
     */
    public function jsonGet(string $key): string {
        return $this->rawCommand('JSON.GET', $key);
    }

    /**
     * @throws Exception
     */
    public function jsonSet(string $key, mixed $value): bool {
        $raw = $this->rawCommand('JSON.SET', $key, '$', $value);

        return $raw === true || $raw === 'OK';
    }

    /**
     * @return array<int, array<string, int|string>>
     *
     * @throws Exception
     */
    public function getModules(): array {
        static $modules = [];

        try {
            $list = $this->rawCommand('MODULE', 'LIST'); // require Redis >= 4.0
        } catch (Exception) {
            return [];
        }

        if (!is_array($list) || $list === []) {
            return [];
        }

        foreach ($list as $module) {
            $modules[] = [
                $module[0] => $module[1], // name
                $module[2] => $module[3], // version
            ];
        }

        return $modules;
    }

    /**
     * @throws Exception
     */
    public function checkModule(string $module): bool {
        return in_array($module, array_column($this->getModules(), 'name'), true);
    }

    /**
     * Combine info values into a single value in a cluster.
     *
     * @param list<mixed>       $values
     * @param list<string>|null $combine
     */
    private function combineValues(string $key, array $values, ?array $combine): mixed {
        $unique = array_unique($values);

        if (count($unique) === 1) {
            return $unique[0];
        }

        $numeric = array_filter($values, is_numeric(...));

        if ($combine && in_array($key, $combine, true) && count($numeric) === count($values)) {
            return array_sum($values);
        }

        if ($key === 'mem_fragmentation_ratio' && count($numeric) === count($values)) {
            return round(array_sum($values) / count($values), 2);
        }

        if ($key === 'used_memory_peak' && count($numeric) === count($values)) {
            return max($values);
        }

        return $values;
    }
}
