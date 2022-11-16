<?php
/**
 * This file is part of phpCacheAdmin.
 *
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
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

    public function __construct(Template $template) {
        $this->template = $template;

        $this->servers = Config::get('memcached', []);

        $server = Http::get('server', 'int');
        $this->current_server = array_key_exists($server, $this->servers) ? $server : 0;
    }

    public static function check(): bool {
        return
            extension_loaded('memcached') ||
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
                100 => '#d1fae5',
                200 => '#a7f3d0',
                300 => '#6ee7b7',
                500 => '#10b981',
                600 => '#059669',
                700 => '#047857',
                900 => '#064e3b',
            ],
        ];
    }

    /**
     * Connect to the server.
     *
     * @param array<string, int|string> $server
     *
     * @return Compatibility\Memcached|Compatibility\Memcache|Compatibility\PHPMem
     * @throws DashboardException
     */
    public function connect(array $server) {
        if (extension_loaded('memcached')) {
            $memcached = new Compatibility\Memcached($server);
        } elseif (extension_loaded('memcache')) {
            $memcached = new Compatibility\Memcache($server);
        } elseif (class_exists(Compatibility\PHPMem::class)) {
            $memcached = new Compatibility\PHPMem($server);
        } else {
            throw new DashboardException('Memcache(d) extension or PHPMem client is not installed.');
        }

        if (isset($server['path'])) {
            $memcached_server = $server['path'];

            $memcached->addServer($server['path'], 0);
        } else {
            $server['port'] ??= 11211;

            $memcached_server = $server['host'].':'.$server['port'];

            $memcached->addServer($server['host'], (int) $server['port']);
        }

        if (!$memcached->isConnected()) {
            throw new DashboardException(sprintf('Failed to connect to Memcached server %s.', $memcached_server));
        }

        return $memcached;
    }

    public function ajax(): string {
        $return = '';

        if (isset($_GET['panel'])) {
            $return = Helpers::returnJson($this->serverInfo());
        } else {
            try {
                $memcached = $this->connect($this->servers[$this->current_server]);

                if (isset($_GET['deleteall'])) {
                    $return = $this->deleteAllKeys($memcached);
                }

                if (isset($_GET['delete'])) {
                    $return = Helpers::deleteKey($this->template, static fn (string $key): bool => $memcached->delete($key));
                }
            } catch (DashboardException|MemcachedException $e) {
                $return = $e->getMessage();
            }
        }

        return $return;
    }

    public function infoPanels(): string {
        // Hide panels on these pages.
        if (isset($_GET['moreinfo']) || isset($_GET['form']) || isset($_GET['view'], $_GET['key'])) {
            return '';
        }

        if (extension_loaded('memcached') || extension_loaded('memcache')) {
            $memcached = extension_loaded('memcached') ? 'd' : '';
            $title = 'PHP Memcache'.$memcached.' extension';
            $version = phpversion('memcache'.$memcached);
        } elseif (class_exists(Compatibility\PHPMem::class)) {
            $title = 'PHPMem';
            $version = Compatibility\PHPMem::VERSION;
        }

        return $this->template->render('partials/info', [
            'title'             => $title ?? null,
            'extension_version' => $version ?? null,
            'info'              => [
                'ajax'   => true,
                'panels' => $this->panels(),
            ],
        ]);
    }

    public function dashboard(): string {
        if (count($this->servers) === 0) {
            return 'No servers';
        }

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo();
        } else {
            try {
                $memcached = $this->connect($this->servers[$this->current_server]);

                if (isset($_GET['view'], $_GET['key'])) {
                    $return = $this->viewKey($memcached);
                } elseif (isset($_GET['form'])) {
                    $return = $this->form($memcached);
                } else {
                    $return = $this->mainDashboard($memcached);
                }
            } catch (DashboardException|MemcachedException $e) {
                return $e->getMessage();
            }
        }

        return $return;
    }
}
