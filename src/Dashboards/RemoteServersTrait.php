<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards;

use RobiNN\Pca\Config;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

/**
 * OPcache, APCu, and the Realpath cache of other servers, read from their pca-remote.php.
 * Server 0 is the PHP running this dashboard. The remote servers follow in the order of the "remoteservers" option.
 * Remote servers are only monitored, every action that would change them is hidden and refused.
 */
trait RemoteServersTrait {
    private ?RemoteClient $remote = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $servers = [];

    private int $current_server = 0;

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function remoteServers(): array {
        $servers = [];

        foreach (array_values((array) Config::get('remoteservers', [])) as $id => $server) {
            if (is_array($server) && !empty($server['url'])) {
                $servers[$id + 1] = $server;
            }
        }

        return $servers;
    }

    private function initServers(bool $local): void {
        if ($local) {
            $this->servers[0] = ['name' => 'This server'];
        }

        foreach (self::remoteServers() as $id => $server) {
            $this->servers[$id] = $server + ['host' => (string) parse_url((string) $server['url'], PHP_URL_HOST)];
        }

        $server = Http::get('server', 0);
        $this->current_server = array_key_exists($server, $this->servers) ? $server : (int) array_key_first($this->servers);

        if ($this->current_server !== 0) {
            $this->remote = $this->remoteClient($this->servers[$this->current_server]);
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    protected function remoteClient(array $server): RemoteClient {
        return new RemoteClient($server);
    }

    /**
     * The only place a remote server is requested, the dashboards call it where they show the error.
     * Everything else reads the response with $this->remote->fetched(), so it cannot fail halfway through a page.
     *
     * @param array<int, string> $flags
     *
     * @throws DashboardException
     */
    private function loadRemote(string $section, array $flags = []): void {
        $this->remote?->fetch($section, $flags);
    }

    private function isRemote(): bool {
        return $this->remote !== null;
    }

    private function serversGlobals(): void {
        $this->template->addGlobal('servers', Helpers::serverSelector($this->servers, $this->current_server));

        if ($this->isRemote()) {
            $this->template->addGlobal('readonly', true); // Hides the same actions the read-only mode does.
        }
    }

    private function remoteActionError(): string {
        return Helpers::alert('A remote server can only be monitored.', 'error');
    }

    private function errorAlert(DashboardException $e): string {
        return $this->template->render('components/alert', ['message' => htmlspecialchars($e->getMessage()), 'alert_color' => 'error', 'inline' => true]);
    }
}
