<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Throwable;

trait RedisStreamGroups {
    /**
     * Caps the groups one key view loads, each costs two extra commands.
     */
    private int $max_groups = 25;

    /**
     * Consumer groups of a stream, with their consumers and pending entries.
     *
     * @return array<string, mixed>
     */
    public function streamGroupsInfo(string $key): array {
        try {
            $groups = $this->redis->streamGroups($key);
        } catch (Throwable) {
            return [];
        }

        if ($groups === []) {
            return [];
        }

        $rows = [];
        $total_pending = 0;

        foreach (array_slice($groups, 0, $this->max_groups) as $group) {
            $name = (string) ($group['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $pending = (int) ($group['pending'] ?? 0);
            $total_pending += $pending;

            $rows[] = [
                'name'              => $name,
                'consumers'         => (int) ($group['consumers'] ?? 0),
                'pending'           => $pending,
                'last_delivered_id' => (string) ($group['last-delivered-id'] ?? '-'),
                'entries_read'      => isset($group['entries-read']) ? (int) $group['entries-read'] : null,
                'lag'               => isset($group['lag']) ? (int) $group['lag'] : null,
                'oldest_pending'    => $this->oldestPending($key, $name),
                'consumer_list'     => $this->consumers($key, $name),
            ];
        }

        return [
            'groups'        => $rows,
            'total_pending' => $total_pending,
            'truncated'     => count($groups) > $this->max_groups ? count($groups) : 0,
        ];
    }

    /**
     * The ID of the entry that has been waiting for an acknowledgement the longest.
     */
    private function oldestPending(string $key, string $group): ?string {
        try {
            $pending = $this->redis->streamPending($key, $group);
        } catch (Throwable) {
            return null;
        }

        // [count, min ID, max ID, [[consumer, count], ...]]
        $oldest = $pending[1] ?? null;

        return is_string($oldest) && $oldest !== '' ? $oldest : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function consumers(string $key, string $group): array {
        try {
            $consumers = $this->redis->streamConsumers($key, $group);
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ($consumers as $consumer) {
            $rows[] = [
                'name'     => (string) ($consumer['name'] ?? ''),
                'pending'  => (int) ($consumer['pending'] ?? 0),
                // Both in milliseconds, 'inactive' (Redis 7.2+) counts only successful reads.
                'idle'     => (int) ($consumer['idle'] ?? 0),
                'inactive' => isset($consumer['inactive']) ? (int) $consumer['inactive'] : null,
            ];
        }

        return $rows;
    }
}
