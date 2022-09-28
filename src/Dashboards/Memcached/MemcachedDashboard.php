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
        return
            extension_loaded('memcached') ||
            extension_loaded('memcache') ||
            class_exists(Compatibility\PHPMem::class);
    }

    /**
     * Get dashboard info.
     *
     * @return array<string, string>
     */
    public function getDashboardInfo(): array {
        return [
            'key'   => 'memcached',
            'title' => 'Memcached',
            'color' => 'emerald',
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
    private function connect(array $server) {
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

        // Add to config.dist.php when SASL is fully supported.
        //'sasl_username' => '', // Optional, when not using SASL
        //'sasl_password' => '', // Optional, when not using SASL
        if (isset($server['sasl_username'], $server['sasl_password'])) {
            try {
                $memcached->sasl();
            } catch (MemcachedException $e) {
                throw new DashboardException($e->getMessage());
            }
        }

        try {
            if (!$memcached->isConnected()) {
                throw new DashboardException(sprintf('Failed to connect to Memcached server %s.', $memcached_server));
            }
        } catch (MemcachedException $e) {
            throw new DashboardException($e->getMessage());
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
            } catch (DashboardException|MemcachedException $e) {
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

        if (extension_loaded('memcached') || extension_loaded('memcache')) {
            $memcached = extension_loaded('memcached') ? 'd' : '';
            $title = 'PHP <span class="font-semibold">Memcache'.$memcached.'</span> extension';
            $version = phpversion('memcache'.$memcached);
        } elseif (class_exists(Compatibility\PHPMem::class)) {
            $title = 'PHPMem';
            $version = Compatibility\PHPMem::VERSION;
        }

        return $this->template->render('partials/info', [
            'title'             => $title ?? null,
            'extension_version' => $version ?? null,
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

        if (count($servers) === 0) {
            return 'No servers';
        }

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
            } catch (DashboardException|MemcachedException $e) {
                return $e->getMessage();
            }
        }

        return $return;
    }
}
