<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Format;

trait MemcachedPanels {
    /**
     * @return array<int|string, mixed>
     */
    private function getPanelsData(bool $command_stats = false): array {
        try {
            $title = '';

            if (class_exists(PHPMem::class)) {
                $title = 'PHPMem v'.PHPMem::VERSION;

                $server = $this->servers[$this->current_server];
                if (isset($server['extension']) && $server['extension'] === true && extension_loaded('memcached')) {
                    $title .= ' + Memcached';
                }
            }

            $info = $this->memcached->getServerStats();

            $stats = [
                [
                    'title' => $title,
                    'data'  => [
                        'Version' => $info['version'],
                        'Uptime'  => Format::seconds($info['uptime'] ?? 0, false),
                    ],
                ],
                $this->memoryPanel($info),
                [
                    'title' => 'Keys',
                    'data'  => [
                        'Current'             => Format::number($info['curr_items'] ?? 0),
                        'Total (since start)' => Format::number($info['total_items'] ?? 0),
                        'Evictions'           => Format::number($info['evictions'] ?? 0),
                        'Reclaimed'           => Format::number($info['reclaimed'] ?? 0),
                        'Expired Unfetched'   => Format::number($info['expired_unfetched'] ?? 0),
                        'Evicted Unfetched'   => Format::number($info['evicted_unfetched'] ?? 0),
                    ],
                ],
                [
                    'title' => 'Connections',
                    'data'  => [
                        'Current'  => Format::number($info['curr_connections'] ?? 0).' / '.Format::number($info['max_connections'] ?? 0).' max',
                        'Total'    => Format::number($info['total_connections'] ?? 0),
                        'Rejected' => Format::number($info['rejected_connections'] ?? 0),
                    ],
                ],
                $this->networkPanel($info),
            ];

            if ($command_stats) {
                return array_merge($stats, $this->commandsStatsData($info));
            }

            return $stats;
        } catch (MemcachedException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function networkPanel(array $info): array {
        $uptime = (int) ($info['uptime'] ?? 0);
        $read = (int) ($info['bytes_read'] ?? 0);
        $written = (int) ($info['bytes_written'] ?? 0);

        $rate = static fn (int $bytes): string => $uptime > 0 ? ' ('.Format::bytes(intdiv($bytes, $uptime)).'/s)' : '';

        return [
            'title' => 'Network',
            'data'  => [
                'Read'    => Format::bytes($read).$rate($read),
                'Written' => Format::bytes($written).$rate($written),
                'Total'   => Format::bytes($read + $written),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function memoryPanel(array $info): array {
        $limit_maxbytes = $info['limit_maxbytes'] ?? 0;
        $bytes = $info['bytes'] ?? 0;
        $memory_usage = ($limit_maxbytes > 0) ? round(($bytes / $limit_maxbytes) * 100, 2) : 0;

        return [
            'title' => 'Memory',
            'data'  => [
                'Total' => Format::bytes($limit_maxbytes, 0),
                ['Used', Format::bytes($bytes).' ('.$memory_usage.'%)', $memory_usage],
                'Free'  => Format::bytes($limit_maxbytes - $bytes),
            ],
        ];
    }
}
