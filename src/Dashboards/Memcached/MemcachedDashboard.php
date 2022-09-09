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

    private int $current_server;

    public function __construct(Template $template) {
        $this->template = $template;

        $server = Http::get('server', 'int');
        $this->current_server = array_key_exists($server, Config::get('memcached')) ? $server : 0;
    }

    /**
     * Check if an extension is installed.
     *
     * @return bool
     */
    public static function check(): bool {
        return extension_loaded('memcache') || extension_loaded('memcached');
    }

    /**
     * Get dashboard info.
     *
     * @return array<string, string>
     */
    public function getDashboardInfo(): array {
        return [
            'key'   => 'memcached',
            'title' => 'Memcache(d)',
            'color' => 'emerald',
        ];
    }

    /**
     * Connect to the server.
     *
     * @param array<string, int|string> $server
     *
     * @return Compatibility\Memcache|Compatibility\Memcached
     * @throws DashboardException
     */
    private function connect(array $server) {
        if (extension_loaded('memcached')) {
            $memcached = new Compatibility\Memcached($server);
        } elseif (extension_loaded('memcache')) {
            $memcached = new Compatibility\Memcache($server);
        } else {
            throw new DashboardException('Memcache(d) extension is not installed.');
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
            throw new DashboardException(sprintf('Failed to connect to Memcache(d) server %s.', $memcached_server));
        }

        return $memcached;
    }

    /**
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string {
        $return = '';
        $servers = Config::get('memcached');

        if (isset($_GET['panel'])) {
            $return = Helpers::returnJson($this->serverInfo($servers));
        } else {
            try {
                $memcached = $this->connect($servers[$this->current_server]);

                if (isset($_GET['deleteall'])) {
                    $return = $this->deleteAllKeys($memcached);
                }

                if (isset($_GET['delete'])) {
                    $return = $this->deleteKey($memcached);
                }
            } catch (DashboardException $e) {
                $return = $e->getMessage();
            }
        }

        return $return;
    }

    /**
     * Data for info panels.
     *
     * @return array<string, mixed>
     */
    public function info(): array {
        $info = [];
        $info['ajax'] = true;

        foreach (Config::get('memcached') as $server) {
            $info['panels'][] = [
                'title'            => $server['name'] ?? $server['host'].':'.$server['port'],
                'server_selection' => true,
                'current_server'   => $this->current_server,
                'moreinfo'         => true,
            ];
        }

        return $info;
    }

    /**
     * Show info panels.
     *
     * @return string
     */
    public function showPanels(): string {
        if (isset($_GET['moreinfo']) || isset($_GET['form']) || isset($_GET['view'], $_GET['key'])) {
            return '';
        }

        $memcached = extension_loaded('memcached') ? 'd' : '';

        return $this->template->render('partials/info', [
            'title'             => 'Memcache'.$memcached,
            'extension_version' => phpversion('memcache'.$memcached),
            'info'              => $this->info(),
        ]);
    }

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        $servers = Config::get('memcached');

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($servers);
        } else {
            try {
                $memcached = $this->connect($servers[$this->current_server]);

                if (isset($_GET['view'], $_GET['key'])) {
                    $return = $this->viewKey($memcached);
                } elseif (isset($_GET['form'])) {
                    $return = $this->form($memcached);
                } else {
                    $return = $this->mainDashboard($memcached);
                }
            } catch (DashboardException $e) {
                return $e->getMessage();
            }
        }

        return $return;
    }
}
