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
     * Parse the raw INFO output into sections.
     * A single 'INFO all' call is a lot faster than requesting every section individually.
     *
     * @return array<string, array<string, string>>
     */
    public function parseInfoOutput(string $raw): array {
        $info = [];
        $section = 'server'; // INFO always starts with a section header, just in case

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($line === '') {
                continue;
            }

            if ($line[0] === '#') {
                $section = strtolower(trim(substr($line, 1)));
                continue;
            }

            $pos = strpos($line, ':');

            if ($pos !== false) {
                $info[$section][substr($line, 0, $pos)] = substr($line, $pos + 1);
            }
        }

        return $info;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function parseSectionData(string $section): array {
        /** @var array<string, string|array<int, string>> $info */
        $info = $this->getInfo($section);

        return array_map(static function (array|string $value): array {
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

            parse_str(str_replace(',', '&', $value), $parsed);

            return $parsed;
        }, $info);
    }

    /**
     * @return array<int, array<string, int|string>>
     *
     * @throws Exception
     */
    public function getModules(): array {
        static $modules = null;

        if ($modules !== null) {
            return $modules;
        }

        $modules = [];

        try {
            $list = $this->moduleList(); // require Redis >= 4.0
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
    protected function moduleList(): mixed {
        return $this->rawCommand('MODULE', 'LIST');
    }

    /**
     * @throws Exception
     */
    public function checkModule(string $module): bool {
        return in_array($module, array_column($this->getModules(), 'name'), true);
    }

    /**
     * Parse a PUBSUB NUMSUB reply (flat or associative) into channel => subscribers pairs.
     *
     * @param array<int|string, mixed> $reply
     *
     * @return array<string, int>
     */
    public function parseNumSubReply(array $reply): array {
        $channels = [];

        if (array_is_list($reply)) {
            $count = count($reply);

            for ($i = 0; $i + 1 < $count; $i += 2) {
                $channels[(string) $reply[$i]] = (int) $reply[$i + 1];
            }
        } else {
            foreach ($reply as $channel => $subscribers) {
                $channels[(string) $channel] = (int) $subscribers;
            }
        }

        return $channels;
    }

    /**
     * Helper function for cluster mode.
     *
     * @param array<string, array<string, mixed>> $aggregated
     * @param null|list<string>                   $combine
     *
     * @return array<string, array<string, mixed>>
     */
    public function aggregatedData(array $aggregated, ?array $combine = null): array {
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
    public function combineValues(string $key, array $values, ?array $combine): mixed {
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
