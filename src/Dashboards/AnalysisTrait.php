<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards;

trait AnalysisTrait {
    private int $top_items = 15;

    /**
     * The lowest value still worth collecting, per column. Set once a buffer has been trimmed.
     *
     * @var array<string, int>
     */
    private array $top_thresholds = [];

    /**
     * Cutoffs left over from a previous run would silently drop rows from the next one, so every analysis has to start with this.
     */
    private function resetTopThresholds(): void {
        $this->top_thresholds = [];
    }

    /**
     * Collect a row only if it can still make the top list.
     *
     * @param array<int, array<string, mixed>> $buffer
     * @param array<string, mixed>             $row
     */
    private function collectTop(array &$buffer, array $row, string $column): void {
        if (isset($this->top_thresholds[$column]) && $row[$column] <= $this->top_thresholds[$column]) {
            return;
        }

        $buffer[] = $row;

        if (count($buffer) >= $this->top_items * 10) {
            $buffer = $this->topRows($buffer, $column, $this->top_items);
            $this->top_thresholds[$column] = (int) end($buffer)[$column];
        }
    }

    /**
     * Group a key under its namespace, e.g. 'app:cache:user:1' with a depth of 2 becomes 'app:cache'.
     */
    private function namespaceOf(string $key, string $separator, int $depth = 1): string {
        if ($separator === '') {
            return $key;
        }

        $parts = explode($separator, $key);

        if (count($parts) === 1) {
            return '(no namespace)';
        }

        return implode($separator, array_slice($parts, 0, min($depth, count($parts) - 1)));
    }

    /**
     * Label a value by the first bucket it fits into. Buckets are label => upper bound, ordered ascending.
     *
     * @param array<string, int> $buckets
     */
    private function bucket(int $value, array $buckets): string {
        foreach ($buckets as $label => $max) {
            if ($value < $max) {
                return $label;
            }
        }

        return (string) array_key_last($buckets);
    }

    /**
     * Sort grouped rows (namespaces, types) by a column and add each group's share of the total.
     *
     * @param array<string, array<string, int>> $groups
     *
     * @return array<int, array<string, mixed>>
     */
    private function topGroups(array $groups, string $column, int $total, ?int $limit = null): array {
        uasort($groups, static fn (array $a, array $b): int => $b[$column] <=> $a[$column]);

        if ($limit !== null) {
            $groups = array_slice($groups, 0, $limit, true);
        }

        $rows = [];

        foreach ($groups as $name => $group) {
            $rows[] = [
                'name'    => $name,
                'count'   => $group['count'],
                'memory'  => $group['memory'],
                'percent' => $this->percent($group[$column], $total),
            ];
        }

        return $rows;
    }

    /**
     * Take the highest rows by a column.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function topRows(array $rows, string $column, int $limit): array {
        usort($rows, static fn (array $a, array $b): int => $b[$column] <=> $a[$column]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Turn bucket counts into rows with each bucket's share of the total.
     *
     * @param array<string, int> $counts
     *
     * @return array<int, array<string, mixed>>
     */
    private function distribution(array $counts, int $total): array {
        $rows = [];

        foreach ($counts as $name => $count) {
            $rows[] = ['name' => $name, 'count' => $count, 'percent' => $this->percent($count, $total)];
        }

        return $rows;
    }

    private function percent(int|float $value, int|float $total): float {
        return $total > 0 ? round(($value / $total) * 100, 2) : 0.0;
    }
}
