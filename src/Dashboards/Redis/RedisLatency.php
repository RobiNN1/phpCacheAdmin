<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait RedisLatency {
    /**
     * The latency monitor keeps 160 samples per event, this only guards the table.
     */
    private int $latency_history_limit = 200;

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function latencyTab(): array {
        if (!$this->isCommandSupported('LATENCY')) {
            return ['tab_error' => 'The LATENCY command is disabled on your server.'];
        }

        $this->latencyActions();

        $threshold = $this->latencyThreshold();
        $events = $this->redis->latencyLatest();

        // Worst first, the all-time maximum is what a spike is remembered by.
        usort($events, static fn (array $a, array $b): int => $b['max'] <=> $a['max']);

        $event = (string) Http::get('event', '');
        $event = $event !== '' && in_array($event, array_column($events, 'event'), true) ? $event : '';

        return [
            'latency' => [
                'threshold'     => $threshold,
                'events'        => $events,
                'event'         => $event,
                'history'       => $event !== '' ? array_slice($this->redis->latencyHistory($event), -$this->latency_history_limit) : [],
                'doctor'        => $this->doctorAdvice('latency'),
                'memory_doctor' => $this->isCommandSupported('MEMORY') ? $this->doctorAdvice('memory') : '',
                'commands'      => $this->commandLatency($this->redis->parseSectionData('commandstats'), $this->redis->getInfo('latencystats')),
                'is_cluster'    => $this->is_cluster,
            ],
        ];
    }

    /**
     * Both actions only touch diagnostics, the keyspace is never involved.
     *
     * @throws Exception
     */
    private function latencyActions(): void {
        if (!isset($_POST['save_latency']) && !isset($_POST['reset_latency'])) {
            return;
        }

        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            echo Helpers::alert('Invalid CSRF token.', 'error');

            return;
        }

        if (isset($_POST['save_latency'])) {
            $this->redis->execConfig('SET', 'latency-monitor-threshold', (string) max(0, Http::post('latency_threshold', 0)));
        } else {
            $this->redis->latencyReset();
        }

        Http::redirect(['tab']);
    }

    /**
     * @throws Exception
     */
    private function latencyThreshold(): int {
        $config = $this->redis->execConfig('GET', 'latency-monitor-threshold');

        return (int) (is_array($config) ? ($config['latency-monitor-threshold'] ?? 0) : 0);
    }

    /**
     * The DOCTOR commands need @admin ACL category, which a restricted user may not have.
     */
    private function doctorAdvice(string $type): string {
        try {
            return trim($type === 'memory' ? $this->redis->memoryDoctor() : $this->redis->latencyDoctor());
        } catch (Exception) {
            return '';
        }
    }

    /**
     * Percentiles come from the INFO latencystats section, which the server tracks on its own (latency-tracking, on by default).
     *
     * @param array<int|string, mixed> $latencystats
     *
     * @return array<string, array<string, float>>
     */
    public function parseLatencyPercentiles(array $latencystats): array {
        $percentiles = [];

        foreach ($latencystats as $field => $value) {
            if (!str_starts_with((string) $field, 'latency_percentiles_usec_')) {
                continue;
            }

            $command = substr((string) $field, strlen('latency_percentiles_usec_'));

            // A cluster reports one line per node; a percentile is not an average, so the worst node is kept.
            foreach ($this->fieldValues($latencystats, (string) $field) as $line) {
                foreach (explode(',', $line) as $pair) {
                    if (!str_contains($pair, '=')) {
                        continue;
                    }

                    [$label, $usec] = explode('=', $pair, 2);

                    if (is_numeric($usec)) {
                        $percentiles[$command][$label] = max($percentiles[$command][$label] ?? 0, (float) $usec);
                    }
                }
            }
        }

        return $percentiles;
    }

    /**
     * @param array<string, array<string, mixed>> $commandstats
     * @param array<int|string, mixed>            $latencystats
     *
     * @return array<string, mixed>
     */
    public function commandLatency(array $commandstats, array $latencystats): array {
        $percentiles = $this->parseLatencyPercentiles($latencystats);
        $labels = [];
        $rows = [];

        foreach ($commandstats as $field => $stats) {
            if (!str_starts_with($field, 'cmdstat_')) {
                continue;
            }

            $calls = (int) ($stats['calls'] ?? 0);

            if ($calls === 0) {
                continue;
            }

            $command = substr($field, strlen('cmdstat_'));
            $command_percentiles = $percentiles[$command] ?? [];
            $labels += $command_percentiles;

            $rows[] = [
                'name'        => $command,
                'calls'       => $calls,
                'average'     => round((float) ($stats['usec_per_call'] ?? 0), 3),
                'rejected'    => (int) ($stats['rejected_calls'] ?? 0),
                'failed'      => (int) ($stats['failed_calls'] ?? 0),
                'percentiles' => $command_percentiles,
                'slowest'     => $command_percentiles !== [] ? max($command_percentiles) : (float) ($stats['usec_per_call'] ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['slowest'] <=> $a['slowest']);

        $labels = array_keys($labels);
        usort($labels, static fn (string $a, string $b): int => (float) ltrim($a, 'p') <=> (float) ltrim($b, 'p'));

        return ['labels' => $labels, 'rows' => $rows];
    }
}
