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

    /**
     * @var array<int, array<string, int|string>>
     */
    private array $servers;

    private int $current_server;

    public Compatibility\Redis|Compatibility\Predis|Compatibility\Cluster\RedisCluster|Compatibility\Cluster\PredisCluster $redis;

    public string $client = '';

    public bool $is_cluster = false;

    public function __construct(private readonly Template $template, ?string $client = null) {
        $this->client = $client ?? (extension_loaded('redis') ? 'redis' : 'predis');
        $this->servers = Config::get('redis', []);

        $server = Http::get('server', 0);

        $this->current_server = array_key_exists($server, $this->servers) ? $server : 0;
    }

    public static function check(): bool {
        return extension_loaded('redis') || class_exists(Predis::class);
    }

    /**
     * @return array<string, array<int, string>|string>
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
     * @param array<string, mixed> $server
     *
     * @throws DashboardException
     */
    public function connect(array $server): Compatibility\Redis|Compatibility\Predis|Compatibility\Cluster\RedisCluster|Compatibility\Cluster\PredisCluster {
        $server['database'] = Http::get('db', $server['database'] ?? 0);

        if (!empty($server['authfile'])) {
            $server['password'] = trim(file_get_contents($server['authfile']));
        }

        $this->is_cluster = !empty($server['nodes']) && is_array($server['nodes']);

        if ($this->client === 'redis') {
            $redis = $this->is_cluster ? new Compatibility\Cluster\RedisCluster($server) : new Compatibility\Redis($server);
        } elseif ($this->client === 'predis') {
            $redis = $this->is_cluster ? new Compatibility\Cluster\PredisCluster($server) : new Compatibility\Predis($server);
        } else {
            throw new DashboardException('Redis extension or Predis is not installed.');
        }

        return $redis;
    }

    public function ajax(): string {
        try {
            $this->redis = $this->connect($this->servers[$this->current_server]);

            if (isset($_GET['panels'])) {
                return Helpers::getPanelsJson($this->getPanelsData());
            }

            if (isset($_GET['metrics'])) {
                return (new RedisMetrics($this->redis, $this->template, $this->servers, $this->current_server))->collectAndRespond();
            }

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

        $this->template->addGlobal('servers', Helpers::serverSelector($this->template, $this->servers, $this->current_server));

        try {
            $this->redis = $this->connect($this->servers[$this->current_server]);
            $this->template->addGlobal('ajax_panels', true);
            $panels = $this->template->render('partials/info', ['panels' => $this->getPanelsData()]);
            $this->template->addGlobal('side', $this->dbSelect().$panels);

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
