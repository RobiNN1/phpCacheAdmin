<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use PDO;
use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait RedisTrait {
    use RedisTypes;
    use RedisPanels;
    use RedisKeyView;
    use RedisKeysList;
    use RedisPubSub;

    /**
     * @var array<string, string>
     */
    private array $tabs = [
        'keys'    => 'Keys',
        'slowlog' => 'Slow Log',
        'metrics' => 'Metrics',
        'pubsub'  => 'Pub/Sub',
    ];

    /**
     * @throws Exception
     */
    private function deleteAllKeys(): string {
        if ($this->redis->flushDatabase()) {
            return Helpers::alert($this->template, 'All keys from the current database have been removed.', 'success');
        }

        return Helpers::alert($this->template, 'An error occurred while deleting all keys.', 'error');
    }

    private function moreInfo(): string {
        try {
            $info = $this->redis->getInfo();

            foreach ($this->redis->getModules() as $module) {
                $info['modules'][$module['name']] = $module['ver'];
            }

            if (extension_loaded('redis')) {
                $info += Helpers::getExtIniInfo('redis');
            }

            return $this->template->render('partials/info_table', [
                'panel_title' => Helpers::getServerTitle($this->servers[$this->current_server]),
                'array'       => Helpers::convertTypesToString($info),
            ]);
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array<int, string>
     *
     * @throws Exception
     */
    private function getDatabases(): array {
        $databases = [];

        if (isset($this->servers[$this->current_server]['databases'])) {
            $db_count = (int) $this->servers[$this->current_server]['databases'];
        } else {
            $config = $this->redis->config('GET', 'databases');
            $db_count = (int) ($config['databases'] ?? 16);
        }

        $keyspace = $this->redis->parseSectionData('keyspace');

        for ($d = 0; $d < $db_count; $d++) {
            $label = 'Database '.$d;

            if (isset($keyspace['db'.$d]['keys'])) {
                $count = (int) $keyspace['db'.$d]['keys'];
                $label .= ' ('.Format::number($count).' keys)';
            }

            $databases[$d] = $label;
        }

        return $databases;
    }

    private function dbSelect(): string {
        if ($this->is_cluster) {
            return '';
        }

        try {
            $databases = $this->template->render('components/select', [
                'id'       => 'db_select',
                'options'  => $this->getDatabases(),
                'selected' => Http::get('db', $this->servers[$this->current_server]['database'] ?? 0),
            ]);
        } catch (DashboardException|Exception) {
            $databases = '';
        }

        return $databases;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function keysTab(): array {
        if (isset($_POST['submit_import_key'])) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::import(
                    function (string $key): bool {
                        $exists = $this->redis->exists($key);

                        return is_int($exists) && $exists > 0;
                    },
                    function (string $key, string $value, int $ttl): bool {
                        return $this->redis->restoreKeys($key, ($ttl === -1 ? 0 : $ttl), hex2bin($value));
                    }
                );
            } else {
                echo Helpers::alert($this->template, 'Invalid CSRF token.', 'error');
            }
        }

        $keys = $this->getAllKeys();

        if (isset($_GET['export_btn'])) {
            Helpers::export($this->keysTableView($keys), 'redis_backup', fn (string $key): string => bin2hex($this->redis->dump($key)));
        }

        $paginator = new Paginator($this->template, $keys);
        $paginated_keys = $paginator->getPaginated();

        if (Http::get('view', Config::get('listview', 'table')) === 'tree') {
            $keys_to_display = $this->keysTreeView($paginated_keys);
        } else {
            $keys_to_display = $this->keysTableView($paginated_keys);
        }

        return [
            'keys'      => $keys_to_display,
            'all_keys'  => $this->redis->databaseSize(),
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString(['s'], ['view' => 'key', 'key' => '__key__']),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function slowlogTab(): array {
        if (!$this->isCommandSupported('SLOWLOG')) {
            return ['tab_error' => 'Slowlog is disabled on your server.'];
        }

        if (isset($_POST['resetlog'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::alert($this->template, 'Invalid CSRF token.', 'error');
            } else {
                $this->redis->resetSlowlog();
                Http::redirect(['tab']);
            }
        }

        if (isset($_POST['save'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::alert($this->template, 'Invalid CSRF token.', 'error');
            } else {
                $this->redis->execConfig('SET', 'slowlog-max-len', Http::post('slowlog_max_items', '50'));
                $this->redis->execConfig('SET', 'slowlog-log-slower-than', Http::post('slowlog_slower_than', '1000'));
                Http::redirect(['tab']);
            }
        }

        $slowlog_max_items = (int) $this->redis->execConfig('GET', 'slowlog-max-len')['slowlog-max-len'];
        $slowlog_items = $this->redis->getSlowlog($slowlog_max_items);
        $slowlog_slower_than = $this->redis->execConfig('GET', 'slowlog-log-slower-than')['slowlog-log-slower-than'];

        return [
            'slowlog' => [
                'items'       => $slowlog_items ?? [],
                'max_items'   => $slowlog_max_items,
                'slower_than' => $slowlog_slower_than ?? 1000,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metricsTab(): array {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return ['tab_error' => 'Metrics are disabled because the PDO SQLite driver is not available. Install the sqlite3 extension for PHP.'];
        }

        return [];
    }

    /**
     * @throws Exception
     */
    private function mainDashboard(): string {
        $tab = Http::get('tab', '');
        $tab = array_key_exists($tab, $this->tabs) ? $tab : array_key_first($this->tabs);

        $data = match ($tab) {
            'keys' => $this->keysTab(),
            'slowlog' => $this->slowlogTab(),
            'metrics' => $this->metricsTab(),
            default => [],
        };

        return $data['tab_error'] ?? $this->template->render('dashboards/redis/'.$tab, $data);
    }
}
