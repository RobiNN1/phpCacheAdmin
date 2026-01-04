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
     * @return array<string, array<string, mixed>>
     */
    public function parseSectionData(string $section): array {
        /** @var array<string, string|array<int, string>> $info */
        $info = $this->getInfo($section);

        return array_map(static function ($value): array {
            // Cluster mode
            if (is_array($value)) {
                $aggregated = [];
                foreach ($value as $node_string) {
                    parse_str(str_replace(',', '&', (string) $node_string), $parsed);
                    foreach ($parsed as $k => $v) {
                        $aggregated[$k] = ($aggregated[$k] ?? 0) + (is_numeric($v) ? $v : 0);
                    }
                }

                return $aggregated;
            }

            parse_str(str_replace(',', '&', (string) $value), $parsed);

            return $parsed;
        }, $info);
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
     * Helper function for cluster mode.
     *
     * @param array<string, array<string, mixed>> $aggregated
     * @param null|list<string>                   $combine
     *
     * @return array<string, array<string, mixed>>
     */
    private function aggregatedData(array $aggregated, ?array $combine = null): array {
        $combined_info = [];

        foreach ($aggregated as $section_name => $section_data) {
            foreach ($section_data as $key => $values) {
                if ($section_name === 'commandstats' || $section_name === 'keyspace') {
                    $combined_info[$section_name][$key] = $values;
                    continue;
                }

                if (is_array(reset($values))) {
                    foreach ($values as $sub_key => $sub_values) {
                        $combined_info[$section_name][$key][$sub_key] = $this->combineValues((string) $sub_key, $sub_values, $combine);
                    }
                } else {
                    $combined_info[$section_name][$key] = $this->combineValues($key, $values, $combine);
                }
            }
        }

        return $combined_info;
    }

    /**
     * Helper function for cluster mode.
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
