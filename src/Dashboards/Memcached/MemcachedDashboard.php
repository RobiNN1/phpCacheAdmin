<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class MemcachedDashboard implements DashboardInterface {
    use MemcachedTrait;

    private Template $template;

    /**
     * @var array<int, array<string, int|string>>
     */
    private array $servers;

    private int $current_server;

    /**
     * @var Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem
     */
    public $memcached;

    public function __construct(Template $template) {
        $this->template = $template;

        $this->servers = Config::get('memcached', []);

        $server = Http::get('server', 0);
        $this->current_server = array_key_exists($server, $this->servers) ? $server : 0;
    }

    public static function check(): bool {
        return extension_loaded('memcached') ||
            extension_loaded('memcache') ||
            class_exists(Compatibility\PHPMem::class);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function dashboardInfo(): array {
        return [
            'key'    => 'memcached',
            'title'  => 'Memcached',
            'colors' => [
                50  => '#f2fbf9',
                100 => '#d4f3ec',
                200 => '#a9e6db',
                300 => '#75d3c5',
                400 => '#49b8ab',
                500 => '#2b8e84',
                600 => '#247d76',
                700 => '#206560',
                800 => '#1e514e',
                900 => '#1d4441',
                950 => '#0b2827',
            ],
        ];
    }

    /**
     * Connect to the server.
     *
     * @param array<string, int|string> $server
     *
     * @return Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem
     *
     * @throws DashboardException
     */
    public function connect(array $server) {
        $server['port'] ??= 11211;

        if (extension_loaded('memcached')) {
            $memcached = new Compatibility\Memcached($server);
        } elseif (extension_loaded('memcache')) {
            $memcached = new Compatibility\Memcache($server);
        } elseif (class_exists(Compatibility\PHPMem::class)) {
            $memcached = new Compatibility\PHPMem($server);
        } else {
            throw new DashboardException('Memcache(d) extension or PHPMem client is not installed.');
        }

        if (!$memcached->isConnected()) {
            $connection = $server['path'] ?? $server['host'].':'.$server['port'];
            throw new DashboardException(sprintf('Failed to connect to Memcached server %s.', $connection));
        }

        return $memcached;
    }

    public function ajax(): string {
        try {
            $this->memcached = $this->connect($this->servers[$this->current_server]);

            if (isset($_GET['deleteall'])) {
                return $this->deleteAllKeys();
            }

            if (isset($_GET['delete'])) {
                return Helpers::deleteKey($this->template, fn (string $key): bool => $this->memcached->delete($key));
            }
        } catch (DashboardException|MemcachedException $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function dashboard(): string {
        if ($this->servers === []) {
            return 'No servers';
        }

        try {
            $this->memcached = $this->connect($this->servers[$this->current_server]);

            if (isset($_GET['moreinfo'])) {
                return $this->moreInfo();
            }

            if (isset($_GET['view'], $_GET['key'])) {
                return $this->viewKey();
            }

            if (isset($_GET['form'])) {
                return $this->form();
            }

            return $this->mainDashboard();
        } catch (DashboardException|MemcachedException $e) {
            return $e->getMessage();
        }
    }
}
