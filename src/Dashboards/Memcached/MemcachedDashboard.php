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

use RobiNN\Pca\Admin;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Template;

class MemcachedDashboard implements DashboardInterface {
    use MemcachedTrait;

    private Template $template;

    private int $current_server;

    public function __construct(Template $template) {
        $this->template = $template;

        $this->current_server = Admin::get('server', 'int');
    }

    /**
     * Connect to the server.
     *
     * @param array $server
     *
     * @return MemcacheCompatibility\Memcache|MemcacheCompatibility\Memcached
     * @throws DashboardException
     */
    private function connect(array $server) {
        if (extension_loaded('memcached')) {
            $memcache = new MemcacheCompatibility\Memcached();
        } elseif (extension_loaded('memcache')) {
            $memcache = new MemcacheCompatibility\Memcache($server);
        } else {
            throw new DashboardException('Memcache(d) extension is not installed.');
        }

        $server['port'] ??= 11211;

        $memcache->addServer($server['host'], $server['port']);

        if (!$memcache->isConnected()) {
            throw new DashboardException(
                sprintf('Failed to connect to Memcache(d) server (%s:%s).', $server['host'], $server['port'])
            );
        }

        return $memcache;
    }

    /**
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string {
        $return = '';
        $servers = Admin::getConfig('memcached');

        if (isset($_GET['panel'])) {
            $return = Admin::returnJson($this->serverInfo($servers));
        } else {
            try {
                $connect = $this->connect($servers[$this->current_server]);

                if (isset($_GET['deleteall'])) {
                    $return = $this->deleteAllKeys($connect);
                }

                if (isset($_GET['delete'])) {
                    $return = $this->deleteKeys($connect);
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
     * @return array
     */
    public function info(): array {
        $info = [];
        $info['ajax'] = true;

        foreach (Admin::getConfig('memcached') as $server) {
            $info['panel'][] = [
                'title'            => $server['name'] ?? $server['host'].':'.$server['port'],
                'server_selection' => true,
                'current_server'   => $this->current_server,
                'moreinfo'         => true,
            ];
        }

        return $info;
    }

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashborad(): string {
        $servers = Admin::getConfig('memcached');

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($servers);
        } else {
            try {
                $connect = $this->connect($servers[$this->current_server]);

                if (isset($_GET['view']) && !empty($_GET['key'])) {
                    $key = Admin::get('key');

                    $return = $this->template->render('partials/view_key', [
                        'value'    => $connect->get($key),
                        'type'     => 'string',
                        'edit_url' => Admin::queryString(['db'], ['form' => 'edit', 'key' => $key]),
                    ]);
                } elseif (isset($_GET['form'])) {
                    $return = $this->form($connect);
                } else {
                    $return = $this->mainDashboard($connect);
                }
            } catch (DashboardException $e) {
                return $e->getMessage();
            }
        }

        return $return;
    }
}
