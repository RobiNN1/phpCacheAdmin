<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use JsonException;
use Random\RandomException;
use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Dashboards\OPCache\OPCacheDashboard;
use RobiNN\Pca\Dashboards\Realpath\RealpathDashboard;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use Throwable;

/**
 * Settings changed in the UI, saved to a file that is loaded over config.php. ENV variables still win.
 *
 * Off unless 'settings' => true (PCA_SETTINGS=true).
 */
class Settings {
    /**
     * Options outside the server lists, grouped as in config.php (the 'general' group is the top level).
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public const OPTIONS = [
        'general'          => [
            'readonly'       => ['type' => 'bool', 'label' => 'Read-only', 'default' => false, 'help' => 'Block all destructive actions (deleting, editing, consoles, ...).'],
            'console'        => ['type' => 'bool', 'label' => 'Consoles', 'default' => true],
            'keymodal'       => ['type' => 'bool', 'label' => 'Open keys in a modal', 'default' => false],
            'listview'       => ['type' => 'select', 'label' => 'Key list view', 'default' => 'table', 'options' => ['table' => 'Table', 'tree' => 'Tree']],
            'timezone'       => ['type' => 'timezone', 'label' => 'Timezone', 'default' => '', 'placeholder' => 'Automatic'],
            'timeformat'     => ['type' => 'text', 'label' => 'Time format', 'default' => 'd. m. Y H:i:s', 'help' => 'PHP date() format.'],
            'decimalsep'     => ['type' => 'text', 'label' => 'Decimal separator', 'default' => ',', 'max' => 3, 'raw' => true],
            'thousandssep'   => ['type' => 'text', 'label' => 'Thousands separator', 'default' => ' ', 'max' => 3, 'raw' => true],
            'sortthreshold'  => ['type' => 'int', 'label' => 'Sort threshold', 'default' => 100_000, 'min' => 0, 'help' => 'Above this many keys a column sort only orders the current page. 0 disables the limit.'],
            'panelrefresh'   => ['type' => 'int', 'label' => 'Panels refresh (s)', 'default' => 30, 'min' => 1, 'max' => 3600],
            'metricsrefresh' => ['type' => 'int', 'label' => 'Metrics refresh (s)', 'default' => 60, 'min' => 1, 'max' => 3600],
            'metricstab'     => ['type' => 'select', 'label' => 'Default metrics range', 'default' => '1d', 'options' => ['1h' => 'Last hour', '1d' => 'Last day', '1w' => 'Last week', '1m' => 'Last month']],
            'liverefresh'    => ['type' => 'int', 'label' => 'Live mode interval (s)', 'default' => 2, 'min' => 1, 'max' => 60],
            'metricsmaxage'  => ['type' => 'int', 'label' => 'Keep metrics (days)', 'default' => 30, 'min' => 0, 'max' => 3650, 'help' => '0 keeps everything.'],
        ],
        'redisoptions'     => [
            'client'          => ['type' => 'select', 'label' => 'Client', 'default' => '', 'options' => ['' => 'Automatic', 'redis' => 'phpredis', 'predis' => 'Predis']],
            'scanthreshold'   => ['type' => 'int', 'label' => 'SCAN threshold', 'default' => 100_000, 'min' => 0, 'help' => 'Use SCAN instead of KEYS when a database has more keys than this.'],
            'scansize'        => ['type' => 'int', 'label' => 'SCAN size', 'default' => null, 'min' => 1, 'placeholder' => 'Off', 'help' => 'Always use SCAN and list at most this many keys.'],
            'pubsubrefresh'   => ['type' => 'int', 'label' => 'Pub/Sub channels refresh (s)', 'default' => 5, 'min' => 1, 'max' => 3600],
            'pubsubwindow'    => ['type' => 'int', 'label' => 'Pub/Sub capture window (s)', 'default' => 5, 'min' => 1, 'max' => 10],
            'blockedcommands' => ['type' => 'list', 'label' => 'Blocked console commands', 'default' => [], 'help' => 'One command per line, e.g. CONFIG SET.'],
        ],
        'memcachedoptions' => [
            'blockedcommands' => ['type' => 'list', 'label' => 'Blocked console commands', 'default' => [], 'help' => 'One command per line, e.g. flush_all. The case does not matter.'],
        ],
        'apcu'             => [
            'separator' => ['type' => 'text', 'label' => 'Tree view separator', 'default' => ':', 'max' => 10, 'raw' => true],
        ],
    ];

    /**
     * Fields of one server per list.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public const SERVERS = [
        'redis'         => [
            'name'             => ['type' => 'text', 'label' => 'Name', 'placeholder' => 'Localhost'],
            'host'             => ['type' => 'text', 'label' => 'Host', 'placeholder' => '127.0.0.1'],
            'port'             => ['type' => 'int', 'label' => 'Port', 'placeholder' => '6379', 'min' => 1, 'max' => 65535],
            'database'         => ['type' => 'int', 'label' => 'Database', 'placeholder' => '0', 'min' => 0],
            'username'         => ['type' => 'text', 'label' => 'Username (ACL)'],
            'password'         => ['type' => 'secret', 'label' => 'Password'],
            'path'             => ['type' => 'text', 'label' => 'Unix socket', 'placeholder' => '/var/run/redis/redis.sock'],
            'scheme'           => ['type' => 'select', 'label' => 'Connection', 'options' => ['' => 'TCP', 'tls' => 'TLS']],
            'nodes'            => ['type' => 'list', 'label' => 'Cluster nodes', 'help' => 'One host:port per line, replaces the host.'],
            'sentinels'        => ['type' => 'list', 'label' => 'Sentinels', 'help' => 'One host:port per line, replaces the host.'],
            'sentinelmaster'   => ['type' => 'text', 'label' => 'Sentinel master', 'placeholder' => 'mymaster'],
            'sentinelpassword' => ['type' => 'secret', 'label' => 'Sentinel password'],
            'databases'        => ['type' => 'int', 'label' => 'Number of databases', 'min' => 1, 'help' => 'Only needed when CONFIG is disabled.'],
            'separator'        => ['type' => 'text', 'label' => 'Tree view separator', 'placeholder' => ':', 'max' => 10, 'raw' => true],
            'authfile'         => ['type' => 'text', 'label' => 'Password file', 'help' => 'E.g. a Docker secret, used instead of the password.'],
            'ssl'              => ['type' => 'json', 'label' => 'SSL options (JSON)', 'placeholder' => '{"cafile": "ca.pem", "verify_peer": true}'],
        ],
        'memcached'     => [
            'name'      => ['type' => 'text', 'label' => 'Name', 'placeholder' => 'Localhost'],
            'host'      => ['type' => 'text', 'label' => 'Host', 'placeholder' => '127.0.0.1'],
            'port'      => ['type' => 'int', 'label' => 'Port', 'placeholder' => '11211', 'min' => 1, 'max' => 65535],
            'path'      => ['type' => 'text', 'label' => 'Unix socket', 'placeholder' => '/var/run/memcached/memcached.sock'],
            'separator' => ['type' => 'text', 'label' => 'Tree view separator', 'placeholder' => ':', 'max' => 10, 'raw' => true],
            'extension' => ['type' => 'bool', 'label' => 'Use the Memcached extension for get/set'],
        ],
        'remoteservers' => [
            'name'    => ['type' => 'text', 'label' => 'Name'],
            'url'     => ['type' => 'url', 'label' => 'URL of pca-remote.php', 'placeholder' => 'https://web2.example.com/pca-remote.php'],
            'token'   => ['type' => 'secret', 'label' => 'Token'],
            'timeout' => ['type' => 'int', 'label' => 'Timeout (s)', 'placeholder' => '3', 'min' => 1, 'max' => 60],
            'ssl'     => ['type' => 'json', 'label' => 'SSL options (JSON)'],
        ],
    ];

    /**
     * Tabs of the modal with their option group and server list, shown when one of the dashboards is enabled (none = always).
     *
     * @var array<string, array{title: string, dashboards: array<int, class-string>, options: ?string, list: ?string}>
     */
    private const TABS = [
        'general'       => ['title' => 'General', 'dashboards' => [], 'options' => 'general', 'list' => null],
        'redis'         => ['title' => 'Redis', 'dashboards' => [RedisDashboard::class], 'options' => 'redisoptions', 'list' => 'redis'],
        'memcached'     => ['title' => 'Memcached', 'dashboards' => [MemcachedDashboard::class], 'options' => 'memcachedoptions', 'list' => 'memcached'],
        'apcu'          => ['title' => 'APCu', 'dashboards' => [APCuDashboard::class], 'options' => 'apcu', 'list' => null],
        'remoteservers' => ['title' => 'Remote servers', 'dashboards' => [OPCacheDashboard::class, APCuDashboard::class, RealpathDashboard::class], 'options' => null, 'list' => 'remoteservers'],
    ];

    /**
     * A server needs at least one of these.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED = [
        'redis'         => ['host', 'path', 'nodes', 'sentinels'],
        'memcached'     => ['host', 'path'],
        'remoteservers' => ['url'],
    ];

    public static function isEnabled(): bool {
        return Config::get('settings', false) === true;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function file(array $config): string {
        if (isset($config['settingsfile']) && is_string($config['settingsfile']) && $config['settingsfile'] !== '') {
            return $config['settingsfile'];
        }

        return (is_string($config['tmpdir'] ?? null) ? $config['tmpdir'] : __DIR__.'/../tmp').'/settings.php';
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(string $file): array {
        if (!is_file($file)) {
            return [];
        }

        try {
            $data = include $file;
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Saved settings over the config file. A null removes the option, a server list replaces the whole list.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $saved
     *
     * @return array<string, mixed>
     */
    public static function merge(array $config, array $saved): array {
        foreach ($saved as $key => $value) {
            if ($key !== 'general' && array_key_exists($key, self::OPTIONS) && is_array($value)) {
                $options = is_array($config[$key] ?? null) ? $config[$key] : [];

                foreach ($value as $option => $option_value) {
                    if ($option_value === null) {
                        unset($options[$option]);
                    } else {
                        $options[$option] = $option_value;
                    }
                }

                $config[$key] = $options;
            } elseif ($value === null) {
                unset($config[$key]);
            } else {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * The reason why the settings cannot be saved, if any.
     */
    public static function blockedBy(): ?string {
        if (!self::isEnabled()) {
            return 'The settings are disabled.';
        }

        if (Auth::isEnabled() && Auth::user() === null) {
            return 'Log in to change the settings.';
        }

        if ((Config::base()['readonly'] ?? false)) {
            return 'Read-only mode is enabled in the configuration.';
        }

        return null;
    }

    /**
     * Data for the modal. Secrets are never sent to the browser, only whether they are set.
     *
     * @return array<string, mixed>
     */
    public static function view(): array {
        $tabs = self::enabledTabs();
        $options = [];

        foreach (array_filter(array_column($tabs, 'options')) as $group) {
            foreach (self::OPTIONS[$group] as $key => $field) {
                $value = $group === 'general' ? Config::get($key, $field['default']) : Config::get($group.'.'.$key, $field['default']);

                $options[$group][$key] = $field + [
                        'value'  => self::formValue($value, $field['type']),
                        'locked' => self::envVar($group, $key),
                    ];
            }
        }

        $lists = [];

        foreach (array_filter(array_column($tabs, 'list')) as $list) {
            $servers = [];

            foreach ((array) Config::get($list, []) as $server) {
                $row = [];

                foreach (self::SERVERS[$list] as $key => $field) {
                    $value = is_array($server) ? ($server[$key] ?? null) : null;
                    $row[$key] = $field['type'] === 'secret' ? ($value !== null && $value !== '') : self::formValue($value, $field['type']);
                }

                $servers[] = $row;
            }

            $lists[$list] = [
                'fields'  => self::SERVERS[$list],
                'servers' => $servers,
                'locked'  => self::envVar($list),
            ];
        }

        return [
            'tabs'      => $tabs,
            'options'   => $options,
            'lists'     => $lists,
            'blocked'   => self::blockedBy(),
            'file'      => self::file(Config::base()),
            'timezones' => timezone_identifiers_list(),
        ];
    }

    public static function handle(): string {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        $blocked = self::blockedBy();

        if ($blocked !== null) {
            return Helpers::ajaxJson(['errors' => [$blocked]]);
        }

        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            return Helpers::ajaxJson(['errors' => ['Invalid CSRF token.']]);
        }

        try {
            $input = json_decode((string) ($_POST['settings'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $input = null;
        }

        if (!is_array($input)) {
            return Helpers::ajaxJson(['errors' => ['Invalid request.']]);
        }

        $file = self::file(Config::base());
        $errors = [];
        $saved = self::build($input, self::read($file), $errors);

        if ($errors !== []) {
            return Helpers::ajaxJson(['errors' => $errors]);
        }

        if (!self::write($file, $saved)) {
            return Helpers::ajaxJson(['errors' => ['Could not write to '.$file.'. Give the web server write access to that directory.']]);
        }

        return Helpers::ajaxJson(['message' => 'Settings saved.']);
    }

    /**
     * Validated input as the content of the settings file. Only values that differ from config.php are kept.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current The settings file as it is now.
     * @param array<int, string>   $errors
     *
     * @return array<string, mixed>
     */
    public static function build(array $input, array $current, array &$errors): array {
        $saved = $current;
        $tabs = self::enabledTabs();

        foreach (array_filter(array_column($tabs, 'options')) as $group) {
            ['set' => $set, 'drop' => $drop] = self::buildOptions($group, is_array($input[$group] ?? null) ? $input[$group] : [], $errors);

            if ($group === 'general') {
                $saved = array_diff_key(array_replace($saved, $set), $drop);
                continue;
            }

            $options = array_diff_key(array_replace(is_array($saved[$group] ?? null) ? $saved[$group] : [], $set), $drop);

            if ($options === []) {
                unset($saved[$group]);
            } else {
                $saved[$group] = $options;
            }
        }

        $lists = is_array($input['lists'] ?? null) ? $input['lists'] : [];

        foreach (array_filter(array_column($tabs, 'list')) as $list) {
            if (!is_array($lists[$list] ?? null) || self::envVar($list) !== null) {
                continue;
            }

            $servers = self::buildServers($list, $lists[$list], $errors);

            if ($servers === self::baseServers($list)) {
                unset($saved[$list]);
            } else {
                $saved[$list] = $servers;
            }
        }

        return $saved;
    }

    /**
     * Options of one group that differ from config.php (set, a null removes the value) and the ones that match it (drop).
     *
     * @param array<string, mixed> $values
     * @param array<int, string>   $errors
     *
     * @return array{set: array<string, mixed>, drop: array<string, true>}
     */
    private static function buildOptions(string $group, array $values, array &$errors): array {
        $base = Config::base();
        $base_values = $group === 'general' ? $base : (array) ($base[$group] ?? []);
        $set = [];
        $drop = [];
        $ignored = [];

        foreach (self::OPTIONS[$group] as $key => $field) {
            if (!array_key_exists($key, $values) || self::envVar($group, $key) !== null) {
                continue;
            }

            $value = self::normalize($values[$key], $field, $field['label'], $errors);

            if ($value === self::normalize($base_values[$key] ?? $field['default'], $field, '', $ignored)) {
                $drop[$key] = true;
            } else {
                $set[$key] = $value;
            }
        }

        return ['set' => $set, 'drop' => $drop];
    }

    /**
     * @param array<int|string, mixed> $rows
     * @param array<int, string>       $errors
     *
     * @return array<int, array<string, mixed>>
     */
    private static function buildServers(string $list, array $rows, array &$errors): array {
        $existing = array_values((array) Config::get($list, []));
        $servers = [];

        foreach (array_values($rows) as $i => $row) {
            $label = self::TABS[$list]['title'].' server #'.($i + 1);

            if (!is_array($row)) {
                $errors[] = $label.': invalid data.';
                continue;
            }

            $index = $row['_index'] ?? null;
            $previous = is_int($index) && is_array($existing[$index] ?? null) ? $existing[$index] : [];
            $servers[] = self::server($list, $row, $previous, $label, $errors);
        }

        return $servers;
    }

    /**
     * Servers from config.php in the same form as the saved ones, to tell whether the list changed.
     *
     * @return array<int, mixed>
     */
    private static function baseServers(string $list): array {
        $ignored = [];
        $servers = [];

        foreach ((array) (Config::base()[$list] ?? []) as $server) {
            $servers[] = is_array($server) ? self::server($list, $server, $server, '', $ignored) : $server;
        }

        return $servers;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $previous The same server before the change, for the secrets left untouched and the options that the modal does not show.
     * @param array<int, string>   $errors
     *
     * @return array<string, mixed>
     */
    private static function server(string $list, array $row, array $previous, string $label, array &$errors): array {
        $server = array_diff_key($previous, self::SERVERS[$list]);

        foreach (self::SERVERS[$list] as $key => $field) {
            $value = $row[$key] ?? null;

            if ($field['type'] === 'secret' && !is_string($value)) {
                $value = $previous[$key] ?? null;
            }

            $value = self::normalize($value, $field, $label.': '.$field['label'], $errors);

            if ($value !== null && $value !== false) { // An unchecked option is left out, as in config.php.
                $server[$key] = $value;
            }
        }

        $required = self::REQUIRED[$list] ?? [];

        if ($label !== '' && $required !== [] && array_intersect_key($server, array_flip($required)) === []) {
            $errors[] = $label.': '.($list === 'remoteservers' ? 'the URL is required.' : 'set a host or a socket path.');
        }

        ksort($server);

        return $server;
    }

    /**
     * Convert a form (or config) value to the stored type. Null means not set.
     *
     * @param array<string, mixed> $field
     * @param array<int, string>   $errors
     */
    private static function normalize(mixed $value, array $field, string $label, array &$errors): mixed {
        if ($value === null) {
            return null;
        }

        $error = null;
        $value = self::convert($value, $field, $error);

        if ($error !== null) {
            $errors[] = $label.' '.$error;

            return null;
        }

        return $value === '' || $value === [] ? null : $value;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function convert(mixed $value, array $field, ?string &$error): mixed {
        return match ($field['type']) {
            'bool' => (bool) $value,
            'int' => self::toInt($value, $field, $error),
            'list' => self::toList($value),
            'json' => self::toJson($value, $error),
            'select' => self::toSelect($value, $field, $error),
            'timezone' => self::toTimezone($value, $error),
            'url' => self::toUrl($value, $error),
            default => self::toText($value, $field, $error), // text, secret
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function toInt(mixed $value, array $field, ?string &$error): ?int {
        $string = is_int($value) || is_string($value) ? trim((string) $value) : null;

        if ($string === '') {
            return null;
        }

        if ($string === null || preg_match('/^-?\d+$/', $string) !== 1) {
            $error = 'must be a whole number.';

            return null;
        }

        $int = (int) $string;
        $min = $field['min'] ?? PHP_INT_MIN;
        $max = $field['max'] ?? PHP_INT_MAX;

        if ($int < $min || $int > $max) {
            $error = 'must be '.(isset($field['max']) ? 'between '.$min.' and '.$max : 'at least '.$min).'.';

            return null;
        }

        return $int;
    }

    /**
     * @return array<int, string>
     */
    private static function toList(mixed $value): array {
        if (!is_array($value)) {
            $text = is_scalar($value) ? (string) $value : '';
            $value = preg_split('/\R/', $text) ?: [];
        }

        $items = array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function toJson(mixed $value, ?string &$error): array {
        if (is_array($value)) {
            return $value;
        }

        $json = is_scalar($value) ? trim((string) $value) : '';

        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $error = 'must be a JSON object.';

            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function toSelect(mixed $value, array $field, ?string &$error): string {
        $value = is_scalar($value) ? (string) $value : '';

        if (!array_key_exists($value, $field['options'])) {
            $error = 'has an invalid value.';
        }

        return $value;
    }

    private static function toTimezone(mixed $value, ?string &$error): string {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value !== '' && !in_array($value, timezone_identifiers_list(), true)) {
            $error = 'is not a valid timezone, e.g. Europe/Bratislava.';
        }

        return $value;
    }

    private static function toUrl(mixed $value, ?string &$error): string {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value !== '' && (filter_var($value, FILTER_VALIDATE_URL) === false || preg_match('~^https?://~i', $value) !== 1)) {
            $error = 'must be an http(s) URL.';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function toText(mixed $value, array $field, ?string &$error): string {
        $value = is_scalar($value) ? (string) $value : '';

        if (!($field['raw'] ?? false) && $field['type'] !== 'secret') {
            $value = trim($value);
        }

        if (isset($field['max']) && mb_strlen($value) > $field['max']) {
            $error = 'can be at most '.$field['max'].' characters long.';
        }

        return $value;
    }

    private static function formValue(mixed $value, string $type): string|bool {
        return match ($type) {
            'bool' => (bool) $value,
            'list' => implode("\n", array_map(strval(...), array_filter((array) $value, is_scalar(...)))),
            'json' => self::prettyJson($value),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    private static function prettyJson(mixed $value): string {
        if (!is_array($value) || $value === []) {
            return '';
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return '';
        }
    }

    /**
     * The ENV variable that sets this option (or the server list), the modal cannot override it.
     */
    private static function envVar(string $group, ?string $key = null): ?string {
        $prefix = 'PCA_'.strtoupper($group);
        $names = array_keys(getenv());

        if ($key === null) {
            foreach ($names as $name) {
                if ($name === $prefix || preg_match('/^'.preg_quote($prefix, '/').'_\d+(_|$)/', $name) === 1) {
                    return $name;
                }
            }

            return null;
        }

        $candidates = $group === 'general' ? ['PCA_'.strtoupper($key)] : [$prefix, $prefix.'_'.strtoupper($key)];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Tabs of the enabled dashboards.
     *
     * @return array<string, array{title: string, dashboards: array<int, class-string>, options: ?string, list: ?string}>
     */
    private static function enabledTabs(): array {
        $dashboards = (array) Config::get('dashboards', []);

        return array_filter(self::TABS, static fn (array $tab): bool => $tab['dashboards'] === [] || array_intersect($tab['dashboards'], $dashboards) !== []);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function write(string $file, array $data): bool {
        if (!Helpers::makeDir(dirname($file))) {
            return false;
        }

        try {
            $tmp = $file.'.'.bin2hex(random_bytes(8)).'.php';
        } catch (RandomException) {
            return false;
        }

        $contents = '<?php'.PHP_EOL.'// Saved from the settings modal of phpCacheAdmin, loaded over config.php.'.PHP_EOL.'return '.var_export($data, true).';'.PHP_EOL;

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }

        @chmod($tmp, 0600); // It can contain passwords.

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        clearstatcache(true, $file);

        return true;
    }
}
