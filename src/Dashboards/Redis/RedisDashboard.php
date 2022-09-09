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
use RedisException;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class RedisDashboard implements DashboardInterface {
    use RedisTrait;

    private Template $template;

    private int $current_server;

    public function __construct(Template $template) {
        $this->template = $template;

        $servers = Config::get('redis');
        $server = Http::get('server', 'int');

        $this->current_server = array_key_exists($server, $servers) ? $server : 0;
    }

    /**
     * Check if an extension is installed.
     *
     * @return bool
     */
    public static function check(): bool {
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
     * @param array<string, int|string> $server
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

        if (isset($server['path'])) {
            $redis_server = $server['path'];
        } else {
            $server['port'] ??= 6379;

            $redis_server = $server['host'].':'.$server['port'];
        }

        try {
            if (isset($server['path'])) {
                $redis->connect($server['path']);
            } else {
                $redis->connect($server['host'], (int) $server['port'], 3);
            }
        } catch (Exception $e) {
            throw new DashboardException(
                sprintf('Failed to connect to Redis server %s. Error: %s', $redis_server, $e->getMessage())
            );
        }

        try {
            if (isset($server['password'])) {
                $redis->auth($server['password']);
            }
        } catch (Exception $e) {
            throw new DashboardException(
                sprintf('Could not authenticate with Redis server %s. Error: %s', $redis_server, $e->getMessage())
            );
        }

        try {
            $redis->select(Http::get('db', 'int', $server['database'] ?? 0));
        } catch (Exception $e) {
            throw new DashboardException(
                sprintf('Could not select Redis database %s. Error: %s', $redis_server, $e->getMessage())
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
            } catch (DashboardException|RedisException $e) {
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

        if (empty($servers)) {
            return 'No servers';
        }

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($servers);
        } else {
            try {
                $redis = $this->connect($servers[$this->current_server]);

                if (isset($_GET['view'], $_GET['key'])) {
                    $return = $this->viewKey($redis);
                } elseif (isset($_GET['form'])) {
                    $return = $this->form($redis);
                } else {
                    $return = $this->mainDashboard($redis);
                }
            } catch (DashboardException|RedisException $e) {
                return $e->getMessage();
            }
        }

        return $return;
    }
}
