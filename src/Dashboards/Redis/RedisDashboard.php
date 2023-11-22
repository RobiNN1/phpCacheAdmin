<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use Predis\Client as Predis;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class RedisDashboard implements DashboardInterface {
    use RedisTrait;

    private Template $template;

    /**
     * @var array<int, array<string, int|string>>
     */
    private array $servers;

    private int $current_server;

    /**
     * @var Compatibility\Redis|Compatibility\Predis
     */
    public $redis;

    public function __construct(Template $template) {
        $this->template = $template;

        $this->servers = Config::get('redis', []);

        $server = Http::get('server', 0);

        $this->current_server = array_key_exists($server, $this->servers) ? $server : 0;
    }

    public static function check(): bool {
        return extension_loaded('redis') || class_exists(Predis::class);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function dashboardInfo(): array {
        return [
            'key'    => 'redis',
            'title'  => 'Redis',
            'colors' => [
                50  => '#fef3f2',
                100 => '#fee4e2',
                200 => '#fececa',
                300 => '#fcaba5',
                400 => '#f77b72',
                500 => '#ee5145',
                600 => '#dc382c',
                700 => '#b8281d',
                800 => '#98241c',
                900 => '#7f241d',
                950 => '#450e0a',
            ],
        ];
    }

    /**
     * Connect to the server.
     *
     * @param array<string, int|string> $server
     *
     * @return Compatibility\Redis|Compatibility\Predis
     *
     * @throws DashboardException
     */
    public function connect(array $server) {
        $server['database'] = Http::get('db', $server['database'] ?? 0);

        if (isset($server['authfile'])) {
            $server['password'] = trim(file_get_contents($server['authfile']));
        }

        if (extension_loaded('redis')) {
            $redis = new Compatibility\Redis();
            $redis->connection($server);
        } elseif (class_exists(Predis::class)) {
            $redis = new Compatibility\Predis($server);
        } else {
            throw new DashboardException('Redis extension or Predis is not installed.');
        }

        return $redis;
    }

    public function ajax(): string {
        try {
            $this->redis = $this->connect($this->servers[$this->current_server]);

            if (isset($_GET['deleteall'])) {
                return $this->deleteAllKeys();
            }

            if (isset($_GET['delete'])) {
                return Helpers::deleteKey($this->template, function (string $key): bool {
                    $delete_key = $this->redis->del($key);

                    return is_int($delete_key) && $delete_key > 0;
                }, true);
            }
        } catch (DashboardException|Exception $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function dashboard(): string {
        if ($this->servers === []) {
            return 'No servers';
        }

        try {
            $this->redis = $this->connect($this->servers[$this->current_server]);

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
        } catch (DashboardException|Exception $e) {
            return $e->getMessage();
        }
    }
}
