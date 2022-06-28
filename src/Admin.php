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

namespace RobiNN\Pca;

class Admin {
    /**
     * @const string phpCacheAdmin version.
     */
    public const VERSION = '1.0.0';

    /**
     * @var array
     */
    private array $dashboards = [];

    /**
     * Get config.
     *
     * @param ?string $key
     *
     * @return mixed
     */
    public static function getConfig(?string $key = null) {
        if (is_file(__DIR__.'/../config.php')) {
            $config = (array) require __DIR__.'/../config.php';
        } else {
            $config = (array) require __DIR__.'/../config.dist.php';
        }

        self::getEnvConfig($config);

        return $config[$key] ?? $config;
    }

    /**
     * Get config from ENV.
     *
     * @param array $config
     *
     * @return void
     */
    private static function getEnvConfig(array &$config): void {
        // All keys must start with PCA_ prefix.
        // E.g.
        // PCA_TIMEFORMAT
        // PCA_REDIS_1_HOST = 1 is server id
        // PCA_MEMCACHED_0_HOST ...
        $vars = preg_grep('/^PCA_/', array_keys(getenv()));

        if (!empty($vars)) {
            foreach ($vars as $var) {
                Helpers::envVarToArray($config, $var, getenv($var));
            }
        }
    }

    /**
     * Get all dashboards.
     *
     * @return array
     */
    public function getDashboards(): array {
        return $this->dashboards;
    }

    /**
     * Get dashboard object.
     *
     * @param string $dashboard
     *
     * @return object
     */
    public function getDashboard(string $dashboard): object {
        return $this->dashboards[$dashboard];
    }

    /**
     * Set dashboard obejct.
     *
     * @param object $dashboard
     *
     * @return void
     */
    public function setDashboard(object $dashboard): void {
        $info = $dashboard->getDashboardInfo();
        $this->dashboards[$info['key']] = $dashboard;
    }

    /**
     * Get current dashboard.
     *
     * @return string
     */
    public function currentDashboard(): string {
        $current = self::get('type');
        $is_installed = false;

        if (array_key_exists($current, $this->getDashboards())) {
            $dashboard = $this->getDashboard($current);
            $is_installed = $dashboard->check();
        }

        return !empty($current) && $is_installed ? $current : 'server';
    }

    /**
     * Paginate array.
     *
     * @param array $keys
     * @param bool  $sort
     * @param int   $default_per_page
     *
     * @return array
     */
    public static function paginate(array &$keys, bool $sort = true, int $default_per_page = 15): array {
        $per_page = (int) self::get('pp', 'int');
        $per_page = !empty($per_page) ? $per_page : $default_per_page;

        if ($sort) {
            usort($keys, static fn ($a, $b) => strcmp((string) $a['key'], (string) $b['key']));
        }

        $keys = array_chunk($keys, $per_page, true);
        array_unshift($keys, '');
        unset($keys[0]);

        $pages = [];

        for ($i = 1, $max = count($keys); $i <= $max; $i++) {
            $pages[] = $i;
        }

        $page = (int) self::get('p', 'int');
        $page = !empty($page) ? $page : 1;

        $first_page = !empty($keys[1]) ? $keys[1] : [];
        $keys = !empty($keys[$page]) ? $keys[$page] : $first_page;

        return [$pages, $page, $per_page];
    }

    /**
     * Query string manipulation.
     *
     * @param array $filter
     * @param array $additional
     *
     * @return string
     */
    public static function queryString(array $filter = [], array $additional = []): string {
        $keep = ['type', 'server'];
        $filter = array_flip(array_merge($keep, $filter));
        $url = parse_url($_SERVER['REQUEST_URI']);

        if (empty($url['query'])) {
            return $url['path'];
        }

        parse_str($url['query'], $query);

        $query = array_intersect_key($query, $filter);
        //$query = array_diff_key($query, $filter); // remove query strings
        $query += $additional;

        return ($query ? '?' : '').http_build_query($query);
    }

    /**
     * Get query parameter.
     *
     * @param string $key
     * @param string $type
     *
     * @return string|int
     */
    public static function get(string $key, string $type = 'string') {
        $filter = FILTER_SANITIZE_FULL_SPECIAL_CHARS;

        if ($type === 'int') {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        if (filter_has_var(INPUT_GET, $key)) {
            $value = filter_input(INPUT_GET, $key, $filter);
        } else {
            $value = isset($_GET[$key]) ? filter_var($_GET[$key], $filter) : null;
        }

        return $type === 'int' ? (int) $value : (string) $value;
    }

    /**
     * Get post value.
     *
     * @param string $key
     * @param string $type
     *
     * @return string|int
     */
    public static function post(string $key, string $type = 'string') {
        $filter = FILTER_SANITIZE_FULL_SPECIAL_CHARS;

        if ($type === 'int') {
            $filter = FILTER_SANITIZE_NUMBER_INT;
        }

        if (filter_has_var(INPUT_POST, $key)) {
            $value = filter_input(INPUT_POST, $key, $filter);
        } else {
            $value = isset($_POST[$key]) ? filter_var($_POST[$key], $filter) : null;
        }

        return $type === 'int' ? (int) $value : (string) $value;
    }

    /**
     * Redirect.
     *
     * @param string $location
     *
     * @return void
     */
    public static function redirect(string $location): void {
        if (!headers_sent()) {
            header('Location: '.$location, true);
        } else {
            echo '<script data-cfasync="false">window.location.replace("'.$location.'");</script>';
        }
    }

    /**
     * Show status badge.
     *
     * @param Template $template
     * @param bool     $enabled
     * @param ?string  $text
     * @param ?array   $badge_text
     *
     * @return string
     */
    public static function enabledDisabledBadge(Template $template, bool $enabled = true, ?string $text = null, ?array $badge_text = null): string {
        $badge_text = $badge_text ?: ['Enabled', 'Disabled'];

        return $template->render('components/badge', [
            'text' => $enabled ? $badge_text[0].$text : $badge_text[1],
            'bg'   => $enabled ? 'bg-green-600' : 'bg-red-600',
            'pill' => true,
        ]);
    }

    /**
     * Show alert.
     *
     * @param Template $template
     * @param string   $message
     * @param ?string  $color
     *
     * @return void
     */
    public static function alert(Template $template, string $message, ?string $color = null): void {
        $template->addTplGlobal('alerts', $template->render('components/alert', [
            'message'     => $message,
            'alert_color' => $color,
        ]));
    }
}
