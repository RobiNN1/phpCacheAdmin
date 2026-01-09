<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
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

    /**
     * @var array<int, array<string, int|string>>
     */
    private array $servers;

    private int $current_server;

    public PHPMem $memcached;

    public function __construct(private readonly Template $template) {
        $this->servers = Config::get('memcached', []);

        $server = Http::get('server', 0);
        $this->current_server = array_key_exists($server, $this->servers) ? $server : 0;
    }

    public static function check(): bool {
        return class_exists(PHPMem::class);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function dashboardInfo(): array {
        return [
            'key'    => 'memcached',
            'title'  => 'Memcached',
            'colors' => [
                50  => '#f2fbf9',
                100 => '#d4f3ec',
                200 => '#a9e6db',
                300 => '#75d3c5',
                400 => '#49b8ab',
                500 => '#2b8e84',
                600 => '#247d76',
                700 => '#206560',
                800 => '#1e514e',
                900 => '#1d4441',
                950 => '#0b2827',
            ],
        ];
    }

    /**
     * Connect to the server.
     *
     * @param array<string, int|string> $server
     *
     * @throws DashboardException
     */
    public function connect(array $server): PHPMem {
        $server['port'] ??= 11211;

        if (class_exists(PHPMem::class)) {
            $memcached = new PHPMem($server);
        } else {
            throw new DashboardException('PHPMem client is not installed.');
        }

        if (!$memcached->isConnected()) {
            $connection = $server['path'] ?? $server['host'].':'.$server['port'];
            throw new DashboardException(sprintf('Failed to connect to Memcached server %s.', $connection));
        }

        return $memcached;
    }

    public function ajax(): string {
        try {
            $this->memcached = $this->connect($this->servers[$this->current_server]);

            if (isset($_GET['panels'])) {
                return Helpers::getPanelsJson($this->getPanelsData(Http::get('tab') === 'commands_stats'));
            }

            if (isset($_GET['metrics'])) {
                return (new MemcachedMetrics($this->memcached, $this->template, $this->servers, $this->current_server))->collectAndRespond();
            }

            if (isset($_GET['namespaces'])) {
                return $this->getNamespacesJson(Http::get('namespaces', ''));
            }

            if (isset($_GET['deleteall'])) {
                return $this->deleteAllKeys();
            }

            if (isset($_GET['delete'])) {
                return Helpers::deleteKey($this->template, fn (string $key): bool => $this->memcached->delete($key));
            }

            if (isset($_GET['deletenamespace'])) {
                return $this->deleteNamespace(Http::get('deletenamespace', ''));
            }
        } catch (DashboardException|MemcachedException $e) {
            return $e->getMessage();
        }

        return '';
    }

    /**
     * Returns child namespaces in JSON format.
     *
     * @throws MemcachedException
     */
    private function getNamespacesJson(string $prefix): string {
        header('Content-Type: application/json');

        $result = $this->keysNamespaceView($prefix);

        try {
            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Deletes all keys from a namespace.
     *
     * @throws MemcachedException
     */
    private function deleteNamespace(string $namespace): string {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';
        // Store the display version (decoded)
        $display_namespace = urldecode($namespace);

        // In versions >= 1.5.19, keys are encoded with URL-encoded separators
        if (version_compare($this->memcached->version(), '1.5.19', '>=')) {
            $encoded_separator = urlencode($separator);
            // Always work with the decoded version and then encode
            $namespace = urldecode($namespace);
            $namespace = str_replace($separator, $encoded_separator, $namespace);
            $separator = $encoded_separator;
        }

        $pattern_with_sep = $namespace.$separator;
        $deleted = 0;
        $all_key_lines = $this->memcached->getKeys();
        $needs_decode = version_compare($this->memcached->version(), '1.5.19', '>=');

        foreach ($all_key_lines as $line) {
            $key_data = $this->memcached->parseLine($line);

            if (isset($key_data['key'])) {
                $key = $key_data['key'];

                if (str_starts_with($key, $pattern_with_sep) || $key === $namespace) {
                    // For versions >= 1.5.19, metadump returns encoded keys
                    // but delete needs the decoded key
                    $delete_key = $needs_decode ? urldecode($key) : $key;
                    if ($this->memcached->delete($delete_key)) {
                        $deleted++;
                    }
                }
            }
        }

        return Helpers::alert($this->template, sprintf('Deleted %d keys from namespace "%s".', $deleted, $display_namespace), 'success');
    }

    public function dashboard(): string {
        if ($this->servers === []) {
            return 'No servers';
        }

        $this->template->addGlobal('servers', Helpers::serverSelector($this->template, $this->servers, $this->current_server));

        try {
            $this->memcached = $this->connect($this->servers[$this->current_server]);
            $this->template->addGlobal('ajax_panels', true);
            $this->template->addGlobal('side', $this->template->render('partials/info', ['panels' => $this->getPanelsData()]));

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
        } catch (DashboardException|MemcachedException $e) {
            return $e->getMessage();
        }
    }
}
