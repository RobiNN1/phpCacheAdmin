<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Settings;

final class SettingsTest extends TestCase {
    private string $dir;

    private string $config_file;

    private string $settings_file;

    /**
     * @var array<string, string|false>
     */
    private array $env_backup = [];

    /**
     * @throws RandomException
     */
    protected function setUp(): void {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/pca_settings_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->config_file = $this->dir.'/config.php';
        $this->settings_file = $this->dir.'/settings.php';

        $this->env_backup['PCA_SETTINGS'] = getenv('PCA_SETTINGS');
        putenv('PCA_SETTINGS');

        $this->config();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION['csrf_token'] = 'pu-test-csrf';
        $_POST = ['csrf_token' => 'pu-test-csrf'];
    }

    protected function tearDown(): void {
        parent::tearDown();

        foreach ($this->env_backup as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }

        Config::reset();
        $_POST = [];
        unset($_SESSION['csrf_token']);

        foreach ((array) glob($this->dir.'/{,.}*', GLOB_BRACE) as $file) {
            if (is_file((string) $file)) {
                unlink((string) $file);
            }
        }

        rmdir($this->dir);
    }

    /**
     * @param array<int, string> $extra_dashboards
     */
    private function config(bool $enabled = true, bool $readonly = false, array $extra_dashboards = []): void {
        file_put_contents($this->config_file, '<?php return '.var_export([
                'settings'         => $enabled,
                'settingsfile'     => $this->settings_file,
                'readonly'         => $readonly,
                'dashboards'       => [
                    RedisDashboard::class,
                    MemcachedDashboard::class,
                    ...$extra_dashboards,
                ],
                'memcachedoptions' => ['blockedcommands' => ['flush_all']],
                'apcu'             => ['separator' => ':', 'other' => 'kept'],
                'redisoptions'     => ['scanthreshold' => 100_000, 'pubsubwindow' => 5],
                'redis'            => [
                    ['name' => 'Local', 'host' => '127.0.0.1', 'port' => 6379, 'password' => 'pu-secret-password', 'authfile' => '/run/secret'],
                ],
                'memcached'        => [['name' => 'Local', 'host' => '127.0.0.1', 'port' => 11211]],
                'timeformat'       => 'd. m. Y H:i:s',
                'timezone'         => 'Europe/Bratislava',
            ], true).';');

        Config::reset();
        Config::setConfigPath($this->config_file);
    }

    private function setEnv(string $name, string $value): void {
        if (!array_key_exists($name, $this->env_backup)) {
            $this->env_backup[$name] = getenv($name);
        }

        putenv($name.'='.$value);
        Config::reset();
        Config::setConfigPath($this->config_file);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function saved(array $settings): void {
        file_put_contents($this->settings_file, '<?php return '.var_export($settings, true).';');
        Config::reset();
        Config::setConfigPath($this->config_file);
    }

    /**
     * The form as the browser sends it for the current config, nothing changed.
     *
     * @return array<string, mixed>
     */
    private function unchangedInput(): array {
        $view = Settings::view();
        $input = ['general' => [], 'redisoptions' => [], 'lists' => []];

        foreach ($view['options'] as $group => $fields) {
            foreach ($fields as $key => $field) {
                $input[$group][$key] = $field['value'];
            }
        }

        foreach ($view['lists'] as $list => $data) {
            foreach ($data['servers'] as $index => $server) {
                $row = ['_index' => $index];

                foreach ($data['fields'] as $key => $field) {
                    $row[$key] = $field['type'] === 'secret' ? null : $server[$key];
                }

                $input['lists'][$list][] = $row;
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function handle(array $input): array {
        $_POST['settings'] = json_encode($input, JSON_THROW_ON_ERROR);

        return (array) json_decode(Settings::handle(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSavedSettingsAreIgnoredWhenDisabled(): void {
        $this->config(false);
        $this->saved(['timeformat' => 'Y-m-d']);

        $this->assertSame('d. m. Y H:i:s', Config::get('timeformat'));
    }

    public function testSavedSettingsGoOverTheConfigFile(): void {
        $this->saved([
            'timeformat'   => 'Y-m-d',
            'timezone'     => null,
            'redisoptions' => ['scansize' => 500, 'pubsubwindow' => null],
            'redis'        => [['name' => 'New', 'host' => '10.0.0.1']],
        ]);

        $this->assertSame('Y-m-d', Config::get('timeformat'));
        $this->assertNull(Config::get('timezone'));
        $this->assertSame(['scanthreshold' => 100_000, 'scansize' => 500], Config::get('redisoptions'));
        $this->assertSame([['name' => 'New', 'host' => '10.0.0.1']], Config::get('redis'));
        $this->assertSame('d. m. Y H:i:s', Config::base()['timeformat']);
    }

    public function testEnvWinsOverSavedSettings(): void {
        $this->saved(['timeformat' => 'Y-m-d', 'redis' => [['host' => '10.0.0.1']]]);
        $this->setEnv('PCA_TIMEFORMAT', 'H:i');
        $this->setEnv('PCA_REDIS_0_HOST', '192.168.0.5');

        $this->assertSame('H:i', Config::get('timeformat'));
        $this->assertSame('192.168.0.5', Config::get('redis')[0]['host']);
    }

    /**
     * @throws JsonException
     */
    public function testUnchangedFormSavesNothing(): void {
        $result = $this->handle($this->unchangedInput());

        $this->assertSame('Settings saved.', $result['message'] ?? null, implode(' ', $result['errors'] ?? []));
        $this->assertSame([], Settings::read($this->settings_file));
    }

    /**
     * @throws JsonException
     */
    public function testOnlyChangedValuesAreSaved(): void {
        $input = $this->unchangedInput();
        $input['general']['readonly'] = true;
        $input['general']['timezone'] = '';
        $input['redisoptions']['scansize'] = '1000';
        $input['redisoptions']['blockedcommands'] = "CONFIG SET\n\n FLUSHALL ";

        $result = $this->handle($input);

        $this->assertSame('Settings saved.', $result['message'] ?? null, implode(' ', $result['errors'] ?? []));
        $this->assertSame([
            'readonly'     => true,
            'timezone'     => null,
            'redisoptions' => ['scansize' => 1000, 'blockedcommands' => ['CONFIG SET', 'FLUSHALL']],
        ], Settings::read($this->settings_file));
        $this->assertSame(0600, fileperms($this->settings_file) & 0777);
    }

    /**
     * @throws JsonException
     */
    public function testServerEditKeepsUntouchedSecretsAndHiddenOptions(): void {
        $input = $this->unchangedInput();
        $input['lists']['redis'][0]['port'] = '6380';
        $input['lists']['redis'][] = ['_index' => null, 'name' => 'Second', 'host' => 'redis2', 'password' => 'pwd2', 'nodes' => ''];

        $result = $this->handle($input);
        $this->assertSame('Settings saved.', $result['message'] ?? null, implode(' ', $result['errors'] ?? []));

        Config::reset();
        Config::setConfigPath($this->config_file);

        $this->assertSame([
            ['authfile' => '/run/secret', 'host' => '127.0.0.1', 'name' => 'Local', 'password' => 'pu-secret-password', 'port' => 6380],
            ['host' => 'redis2', 'name' => 'Second', 'password' => 'pwd2'],
        ], Config::get('redis'));
    }

    /**
     * @throws JsonException
     */
    public function testRemovingAServerAndClearingASecret(): void {
        $input = $this->unchangedInput();
        $input['lists']['memcached'] = [];
        $input['lists']['redis'][0]['password'] = '';

        $this->handle($input);

        Config::reset();
        Config::setConfigPath($this->config_file);

        $this->assertSame([], Config::get('memcached'));
        $this->assertArrayNotHasKey('password', Config::get('redis')[0]);
    }

    /**
     * @throws JsonException
     */
    public function testInvalidValuesAreRejected(): void {
        $input = $this->unchangedInput();
        $input['general']['timezone'] = 'Mars/Olympus';
        $input['general']['liverefresh'] = '120';
        $input['redisoptions']['pubsubwindow'] = 'abc';
        $input['lists']['redis'][0]['ssl'] = '[1, 2]';
        $input['lists']['memcached'][] = ['_index' => null, 'name' => 'Nothing'];

        $result = $this->handle($input);

        $this->assertSame([
            'Timezone is not a valid timezone, e.g. Europe/Bratislava.',
            'Live mode interval (s) must be between 1 and 60.',
            'Pub/Sub capture window (s) must be a whole number.',
            'Redis server #1: SSL options (JSON) must be a JSON object.',
            'Memcached server #2: set a host or a socket path.',
        ], $result['errors'] ?? null);
        $this->assertFileDoesNotExist($this->settings_file);
    }

    /**
     * @throws JsonException
     */
    public function testEnvironmentOptionsAreLocked(): void {
        $this->setEnv('PCA_TIMEFORMAT', 'H:i');
        $this->setEnv('PCA_MEMCACHED_0_HOST', 'cache');

        $view = Settings::view();
        $this->assertSame('PCA_TIMEFORMAT', $view['options']['general']['timeformat']['locked']);
        $this->assertSame('PCA_MEMCACHED_0_HOST', $view['lists']['memcached']['locked']);
        $this->assertNull($view['lists']['redis']['locked']);

        $input = $this->unchangedInput();
        $input['general']['timeformat'] = 'Y';
        $input['lists']['memcached'] = [];

        $this->handle($input);

        $this->assertSame([], Settings::read($this->settings_file));
    }

    /**
     * @throws JsonException
     */
    public function testSavingIsBlockedByReadonlyConfigAndWhenDisabled(): void {
        $this->config(true, true);
        $this->assertSame(['errors' => ['Read-only mode is enabled in the configuration.']], $this->handle([]));

        $this->config(false);
        $this->assertSame(['errors' => ['The settings are disabled.']], $this->handle([]));
    }

    /**
     * @throws JsonException
     */
    public function testReadonlyFromSettingsCanBeTurnedOffAgain(): void {
        $this->saved(['readonly' => true]);
        $this->assertTrue(Config::get('readonly'));

        $input = $this->unchangedInput();
        $input['general']['readonly'] = false;

        $result = $this->handle($input);

        $this->assertSame('Settings saved.', $result['message'] ?? null);
        $this->assertSame([], Settings::read($this->settings_file));
    }

    /**
     * @throws JsonException
     */
    public function testInvalidCsrfToken(): void {
        $_POST['csrf_token'] = 'wrong';

        $this->assertSame(['errors' => ['Invalid CSRF token.']], $this->handle([]));
    }

    /**
     * @throws JsonException
     */
    public function testViewNeverContainsSecrets(): void {
        $view = Settings::view();

        $this->assertTrue($view['lists']['redis']['servers'][0]['password']);
        $this->assertStringNotContainsString('pu-secret-password', (string) json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testTabsFollowTheEnabledDashboards(): void {
        $this->assertSame(['general', 'redis', 'memcached'], array_keys(Settings::view()['tabs']));
        $this->assertArrayNotHasKey('apcu', Settings::view()['options']);

        $this->config(true, false, [APCuDashboard::class]);

        $this->assertSame(['general', 'redis', 'memcached', 'apcu', 'remoteservers'], array_keys(Settings::view()['tabs']));
        $this->assertSame(':', Settings::view()['options']['apcu']['separator']['value']);
    }

    /**
     * @throws JsonException
     */
    public function testMemcachedAndApcuOptions(): void {
        $this->config(true, false, [APCuDashboard::class]);

        $this->assertSame('flush_all', Settings::view()['options']['memcachedoptions']['blockedcommands']['value']);

        $input = $this->unchangedInput();
        $input['memcachedoptions']['blockedcommands'] = "flush_all\nstats reset";
        $input['apcu']['separator'] = '/';

        $result = $this->handle($input);
        $this->assertSame('Settings saved.', $result['message'] ?? null, implode(' ', $result['errors'] ?? []));

        $this->assertSame([
            'memcachedoptions' => ['blockedcommands' => ['flush_all', 'stats reset']],
            'apcu'             => ['separator' => '/'],
        ], Settings::read($this->settings_file));

        Config::reset();
        Config::setConfigPath($this->config_file);

        $this->assertSame(['flush_all', 'stats reset'], Config::get('memcachedoptions.blockedcommands'));
        $this->assertSame(['separator' => '/', 'other' => 'kept'], Config::get('apcu'));
    }

    /**
     * @throws JsonException
     */
    public function testOptionsOfADisabledDashboardAreIgnored(): void {
        $input = $this->unchangedInput();
        $input['apcu'] = ['separator' => '/'];

        $this->handle($input);

        $this->assertSame([], Settings::read($this->settings_file));
    }
}
