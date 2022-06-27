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

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use Redis;
use RobiNN\Pca\Admin;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Template;

class RedisDashboard implements DashboardInterface {
    use RedisTrait;

    private Template $template;

    private int $current_server;

    private int $current_db;

    public function __construct(Template $template) {
        $this->template = $template;

        $this->current_server = Admin::get('server', 'int');

        $server = Admin::getConfig('redis')[$this->current_server];
        $db = !empty($server['database']) ? $server['database'] : null;
        $db_get = Admin::get('db', 'int');
        $this->current_db = $db ?? $db_get;
    }

    /**
     * Connect to the server.
     *
     * @param array $server
     *
     * @return Redis
     * @throws DashboardException
     */
    private function connect(array $server): Redis {
        if (extension_loaded('redis')) {
            $redis = new Redis();
        } else {
            throw new DashboardException('Redis extension is not installed.');
        }

        $server['port'] ??= 6379;

        try {
            $redis->connect($server['host'], (int) $server['port']);
        } catch (Exception $e) {
            throw new DashboardException(
                sprintf('Failed to connect to Redis server (%s:%s). Error: %s', $server['host'], $server['port'], $e->getMessage())
            );
        }

        try {
            if (isset($server['password'])) {
                $redis->auth($server['password']);
            }
        } catch (Exception $e) {
            throw new DashboardException(
                sprintf('Could not authenticate with Redis server (%s:%s). Error: %s', $server['host'], $server['port'], $e->getMessage())
            );
        }

        try {
            $redis->select($this->current_db);
        } catch (Exception $e) {
            throw new DashboardException(
                sprintf('Could not select Redis database (%s:%s). Error: %s', $server['host'], $server['port'], $e->getMessage())
            );
        }

        return $redis;
    }

    /**
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string {
        $return = '';
        $servers = Admin::getConfig('redis');

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

        foreach (Admin::getConfig('redis') as $server) {
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
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        $servers = Admin::getConfig('redis');

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($servers);
        } else {
            try {
                $connect = $this->connect($servers[$this->current_server]);

                if (isset($_GET['view']) && !empty($_GET['key'])) {
                    $key = Admin::get('key');
                    $type = $this->getType($connect->type($key));

                    if (isset($_GET['deletesub'])) {
                        $this->deleteSubKey($connect, $type, $key);
                    }

                    $value = $this->getKeyValues($connect, $type, $key);

                    $pages = [];
                    $page = 0;
                    $per_page = 15;

                    if (is_array($value)) {
                        [$pages, $page, $per_page] = Admin::paginate($value, false);
                    }

                    $return = $this->template->render('partials/view_key', [
                        'value'        => $value,
                        'type'         => $type,
                        'ttl'          => $connect->ttl($key),
                        'edit_url'     => Admin::queryString(['db'], ['form' => 'edit', 'key' => $key]),
                        'delete_url'   => Admin::queryString(['db', 'view', 'p'], ['deletesub' => 'key', 'key' => $key]),
                        'add_subkey'   => Admin::queryString(['db'], ['form' => 'new', 'key' => $key]),
                        'current_page' => $page,
                        'paginate'     => $pages,
                        'paginate_url' => Admin::queryString(['db', 'view', 'key', 'pp'], ['p' => '']),
                        'per_page'     => $per_page,
                    ]);
                } elseif (isset($_GET['form'])) {
                    $return = $this->form($connect);
                } else {
                    $return = $this->mainDashboard($connect);
                }
            } catch (Exception $e) {
                return $e->getMessage();
            }
        }

        return $return;
    }
}
