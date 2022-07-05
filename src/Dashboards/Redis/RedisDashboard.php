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
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class RedisDashboard implements DashboardInterface {
    use RedisTrait;

    private int $current_server;

    private int $current_db;

    public function __construct(Template $template) {
        $this->construct($template); // for RedisTrait

        $this->current_server = Http::get('server', 'int');

        $server = Config::get('redis')[$this->current_server];

        $db = empty($server['database']) ? null : $server['database'];
        $db_get = Http::get('db', 'int');
        $this->current_db = $db ?? $db_get;
    }

    /**
     * Check if extension is installed.
     *
     * @return bool
     */
    public function check(): bool {
        return extension_loaded('redis');
    }

    /**
     * Get dashboard info.
     *
     * @return array<string, string>
     */
    public function getDashboardInfo(): array {
        return [
            'key'   => 'redis',
            'title' => 'Redis',
            'color' => 'red',
        ];
    }

    /**
     * Connect to the server.
     *
     * @param array<string, mixed> $server
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
        $servers = Config::get('redis');

        if (isset($_GET['panel'])) {
            $return = Helpers::returnJson($this->serverInfo($servers));
        } else {
            try {
                $redis = $this->connect($servers[$this->current_server]);

                if (isset($_GET['deleteall'])) {
                    $return = $this->deleteAllKeys($redis);
                }

                if (isset($_GET['delete'])) {
                    $return = $this->deleteKey($redis);
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

        foreach (Config::get('redis') as $server) {
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

        return $this->template->render('partials/info', [
            'title'             => 'Redis',
            'extension_version' => phpversion('redis'),
            'info'              => $this->info(),
        ]);
    }

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        $servers = Config::get('redis');

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($servers);
        } else {
            try {
                $redis = $this->connect($servers[$this->current_server]);

                if (isset($_GET['view']) && !empty($_GET['key'])) {
                    $return = $this->viewKey($redis);
                } elseif (isset($_GET['form'])) {
                    $return = $this->form($redis);
                } else {
                    $return = $this->mainDashboard($redis);
                }
            } catch (Exception $e) {
                return $e->getMessage();
            }
        }

        return $return;
    }
}
