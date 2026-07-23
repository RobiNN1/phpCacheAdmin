<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use RobiNN\Pca\Dashboards\DashboardException;

interface RedisCompatibilityInterface {
    /**
     * Get a key type from an array.
     */
    public function getType(string|int $type): string;

    /**
     * Get a key type.
     *
     * @throws DashboardException
     */
    public function getKeyType(string $key): string;

    /**
     * Get server info.
     *
     * @return array<int|string, mixed>
     */
    public function getInfo(?string $option = null): array;

    /**
     * Alias to a scan().
     *
     * @return array<int, string>
     */
    public function scanKeys(string $pattern, int $count): array;

    /**
     * Alias to a lRem().
     */
    public function listRem(string $key, string $value, int $count): int;

    /**
     * Alias to a xAdd().
     *
     * @param array<string, string> $messages
     */
    public function streamAdd(string $key, string $id, array $messages): string;

    /**
     * Alias to a xInfo('GROUPS').
     *
     * @return array<int, array<string, mixed>>
     */
    public function streamGroups(string $key): array;

    /**
     * Alias to a xInfo('CONSUMERS').
     *
     * @return array<int, array<string, mixed>>
     */
    public function streamConsumers(string $key, string $group): array;

    /**
     * Alias to a xPending().
     *
     * @return array<int, mixed>
     */
    public function streamPending(string $key, string $group): array;

    /**
     * Alias to a xReadGroup().
     *
     * @return array<string, mixed>
     */
    public function streamReadGroup(string $key, string $group, string $consumer, int $count): array;

    /**
     * Alias to a xGroup('CREATE').
     */
    public function streamCreateGroup(string $key, string $group, string $id = '0'): bool;

    /**
     * Alias to a vInfo().
     *
     * @return array<string, mixed>
     */
    public function vectorInfo(string $key): array;

    /**
     * Alias to a vRandMember().
     *
     * @return array<int, string>
     */
    public function vectorMembers(string $key, int $count): array;

    /**
     * Alias to a vEmb().
     *
     * @return array<int, float>
     */
    public function vectorEmbedding(string $key, string $element): array;

    /**
     * Alias to a vGetAttr().
     */
    public function vectorAttributes(string $key, string $element): string;

    /**
     * Alias to a vAdd().
     *
     * @param array<int, float|string> $vector
     */
    public function vectorAdd(string $key, string $element, array $vector, string $attributes = ''): bool;

    /**
     * Alias to a vRem().
     */
    public function vectorRem(string $key, string $element): bool;

    /**
     * Pipeline keys for better performance.
     *
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     */
    public function pipelineKeys(array $keys): array;

    /**
     * Get key size.
     *
     * Requires Redis >= 4.0.0.
     */
    public function size(string $key): int;

    /**
     * Alias to a flushDB().
     */
    public function flushDatabase(): bool;

    /**
     * Alias to a dbSize().
     */
    public function databaseSize(): int;

    /**
     * Alias to a config().
     */
    public function execConfig(string $operation, mixed ...$args): mixed;

    /**
     * Get Slowlog entries.
     *
     * @return null|array<int, mixed>
     */
    public function getSlowlog(int $count): ?array;

    /**
     * Reset Slowlog.
     */
    public function resetSlowlog(): bool;

    /**
     * Check if the server supports a command.
     */
    public function commandExists(string $command): bool;

    /**
     * Get the connected clients with the dashboard's own connection marked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getClients(): array;

    /**
     * Close a client connection by its ID. Returns false when no client had that ID.
     */
    public function killClient(string $id): bool;

    /**
     * Alias to a restore().
     */
    public function restoreKeys(string $key, int $ttl, string $value): bool;

    /**
     * Get active Pub/Sub channels with subscriber counts and the number of pattern subscriptions.
     *
     * @return array{channels: array<string, int>, patterns: int}
     */
    public function pubSubStats(string $pattern = '*'): array;

    /**
     * Publish a message to a channel and get the number of receivers.
     */
    public function publishMessage(string $channel, string $message): int;

    /**
     * Subscribe to a pattern and collect messages for a limited time window.
     *
     * @return array<int, array{channel: string, message: string, time: int}>
     */
    public function captureMessages(string $pattern, int $seconds, int $limit): array;

    /**
     * Run a raw command entered in the console and return its reply.
     *
     * @param array<int, string> $args Command name and its arguments, e.g. ['GET', 'mykey'].
     */
    public function consoleCommand(array $args): mixed;
}
