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

trait RedisClients {
    /**
     * CLIENT LIST flags worth pointing out.
     *
     * @link https://redis.io/docs/latest/commands/client-list/
     *
     * @var array<string, string>
     */
    private array $client_flags = [
        'O' => 'Monitor',
        'S' => 'Replica',
        'M' => 'Master',
        'b' => 'Blocked',
        'x' => 'In MULTI',
        't' => 'Tracking',
    ];

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function clientsTab(): array {
        if (!$this->isCommandSupported('CLIENT')) {
            return ['tab_error' => 'The CLIENT command is disabled on your server.'];
        }

        if (isset($_POST['kill_client'])) {
            $this->killClient(Http::post('kill_client', ''));
        }

        $clients = array_map($this->formatClient(...), $this->redis->getClients());

        // Idle first, those are the ones worth looking at.
        usort($clients, static fn (array $a, array $b): int => $b['idle'] <=> $a['idle']);

        return ['clients' => $clients, 'is_cluster' => $this->is_cluster];
    }

    /**
     * @throws Exception
     */
    private function killClient(string $id): void {
        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            echo Helpers::alert('Invalid CSRF token.', 'error');

            return;
        }

        if ($id === '') {
            return;
        }

        if ($this->redis->killClient($id)) {
            echo Helpers::alert(sprintf('Client %s has been disconnected.', $id), 'success');
        } else {
            // It can also disconnect on its own between rendering the list and pressing the button.
            echo Helpers::alert(sprintf('Client %s is no longer connected.', $id), 'error');
        }
    }

    /**
     * The fields differ between Redis versions, so anything missing is left out rather than guessed.
     *
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    public function formatClient(array $client): array {
        $age = (int) ($client['age'] ?? 0);
        $idle = (int) ($client['idle'] ?? 0);
        $memory = (int) ($client['tot-mem'] ?? 0);

        return [
            'id'      => (string) ($client['id'] ?? ''),
            'addr'    => (string) ($client['addr'] ?? ''),
            'name'    => (string) ($client['name'] ?? ''),
            'user'    => (string) ($client['user'] ?? ''),
            'db'      => (string) ($client['db'] ?? ''),
            'age'     => $age,
            'idle'    => $idle,
            'memory'  => $memory,
            'flags'   => $this->clientFlags((string) ($client['flags'] ?? '')),
            'command' => (string) ($client['cmd'] ?? ''),
            'node'    => (string) ($client['node'] ?? ''),
            'self'    => (bool) ($client['self'] ?? false),
        ];
    }

    /**
     * Name the flags that mean something. A plain connection reports "N", which is worth no space at all.
     *
     * @return array<int, string>
     */
    private function clientFlags(string $flags): array {
        $names = [];

        foreach (str_split($flags) as $flag) {
            if (isset($this->client_flags[$flag])) {
                $names[] = $this->client_flags[$flag];
            }
        }

        return $names;
    }
}
