<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards;

use JsonException;
use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\OPCache\OPCacheDashboard;
use RobiNN\Pca\Dashboards\Realpath\RealpathDashboard;
use RobiNN\Pca\Dashboards\RemoteClient;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

/**
 * The remote server is this PHP through InProcessRemoteClient, so it has to show exactly what the local one does.
 */
final class RemoteServersTest extends TestCase {
    private string|false $env_backup = false;

    private ?InProcessRemoteClient $client = null;

    protected function setUp(): void {
        $this->env_backup = getenv('PCA_REMOTESERVERS');
        putenv('PCA_REMOTESERVERS=[{"name":"Remote","url":"http://remote.test/pca-remote.php","token":"pu-test"}]');
        Config::reset();
    }

    protected function tearDown(): void {
        putenv($this->env_backup === false ? 'PCA_REMOTESERVERS' : 'PCA_REMOTESERVERS='.$this->env_backup);
        Config::reset();

        $_GET = [];
        $_POST = [];

        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    private function skipWithoutApcu(): void {
        if (!extension_loaded('apcu') || ini_get('apc.enable_cli') !== '1') {
            $this->markTestSkipped('APC CLI is not enabled.');
        }
    }

    private function skipWithoutOpcache(): void {
        if (!extension_loaded('Zend OPcache') || @opcache_get_status(false) === false) {
            $this->markTestSkipped('OPcache is not enabled in CLI.');
        }
    }

    /**
     * Server 0 is this PHP, server 1 is the remote one from the environment variable.
     */
    private function selectServer(bool $remote): void {
        $_GET['server'] = $remote ? '1' : '0';
    }

    private function apcu(bool $remote): APCuDashboard {
        $this->selectServer($remote);

        return new class(new Template(), $this) extends APCuDashboard {
            public function __construct(Template $template, private readonly RemoteServersTest $test) {
                parent::__construct($template);
                unset($_GET['server']);
            }

            protected function remoteClient(array $server): InProcessRemoteClient {
                return $this->test->client($server);
            }
        };
    }

    private function opcache(bool $remote): OPCacheDashboard {
        $this->selectServer($remote);

        return new class(new Template(), $this) extends OPCacheDashboard {
            public function __construct(Template $template, private readonly RemoteServersTest $test) {
                parent::__construct($template);
                unset($_GET['server']);
            }

            protected function remoteClient(array $server): InProcessRemoteClient {
                return $this->test->client($server);
            }
        };
    }

    private function realpath(bool $remote): RealpathDashboard {
        $this->selectServer($remote);

        return new class(new Template(), $this) extends RealpathDashboard {
            public function __construct(Template $template, private readonly RemoteServersTest $test) {
                parent::__construct($template);
                unset($_GET['server']);
            }

            protected function remoteClient(array $server): InProcessRemoteClient {
                return $this->test->client($server);
            }
        };
    }

    /**
     * @param array<string, mixed> $server
     */
    public function client(array $server): InProcessRemoteClient {
        return $this->client = new InProcessRemoteClient($server);
    }

    private function lastClient(): InProcessRemoteClient {
        if (!$this->client instanceof InProcessRemoteClient) {
            $this->fail('The remote server was not selected.');
        }

        return $this->client;
    }

    private function panels(APCuDashboard|OPCacheDashboard|RealpathDashboard $dashboard): string {
        $_GET['panels'] = '';

        // Uptime is counted in seconds, both have to be computed in the same one.
        return preg_replace('~"uptime":\[[^]]*]~', '', $dashboard->ajax()) ?? '';
    }

    public function testLocalServerIsTheDefault(): void {
        $this->realpath(false)->dashboard();

        $this->assertNull($this->client);
    }

    public function testRemoteServerIsSelected(): void {
        $this->realpath(true)->dashboard();

        $this->assertSame('Remote', $this->lastClient()->name());
    }

    public function testServerSelectorListsTheRemoteServer(): void {
        $selector = Helpers::serverSelector([0 => ['name' => 'This server'], 1 => ['name' => 'Remote', 'host' => 'remote.test']], 1);

        $this->assertStringContainsString('<option value="1" selected>Remote - remote.test</option>', $selector);
    }

    public function testEnvVariablesPerServer(): void {
        putenv('PCA_REMOTESERVERS');
        putenv('PCA_REMOTESERVERS_0_URL=http://remote.test/pca-remote.php');
        putenv('PCA_REMOTESERVERS_0_TOKEN=pu-test');
        Config::reset();

        try {
            $this->assertSame([['url' => 'http://remote.test/pca-remote.php', 'token' => 'pu-test']], Config::get('remoteservers'));
        } finally {
            putenv('PCA_REMOTESERVERS_0_URL');
            putenv('PCA_REMOTESERVERS_0_TOKEN');
        }
    }

    /**
     * @throws DashboardException
     */
    public function testOneRequestPerSection(): void {
        $client = $this->client(['name' => 'Remote', 'url' => 'http://remote.test/pca-remote.php']);

        $client->fetch('realpath');
        $client->fetch('realpath');

        $this->assertSame(1, $client->requests);
    }

    /**
     * @throws DashboardException
     */
    public function testResponseWithMoreFlagsAnswersFewer(): void {
        $client = $this->client(['name' => 'Remote', 'url' => 'http://remote.test/pca-remote.php']);

        $client->fetch('apcu', ['keys']);
        $client->fetch('apcu');

        $this->assertSame(1, $client->requests);
    }

    /**
     * @throws DashboardException
     */
    public function testResponseWithFewerFlagsDoesNotAnswerMore(): void {
        $client = $this->client(['name' => 'Remote', 'url' => 'http://remote.test/pca-remote.php']);

        $client->fetch('apcu');
        $client->fetch('apcu', ['keys']);

        $this->assertSame(2, $client->requests);
    }

    /**
     * @throws DashboardException
     */
    public function testDifferentProtocol(): void {
        $client = $this->client(['name' => 'Remote', 'url' => 'http://remote.test/pca-remote.php']);
        $client->response = ['protocol' => 999, 'loaded' => true];

        $this->expectExceptionObject(new DashboardException('pca-remote.php on Remote is a different version, update it there.'));

        $client->fetch('realpath');
    }

    /**
     * @throws DashboardException
     */
    public function testExtensionNotLoaded(): void {
        $client = $this->client(['name' => 'Remote', 'url' => 'http://remote.test/pca-remote.php']);
        $client->response = ['protocol' => RemoteClient::PROTOCOL, 'loaded' => false];

        $this->expectExceptionObject(new DashboardException('The APCu extension is not loaded on Remote.'));

        $client->fetch('apcu');
    }

    public function testErrorIsShownInsteadOfTheDashboard(): void {
        $dashboard = $this->realpath(true);
        $this->lastClient()->response = ['error' => 'Invalid token.'];

        $this->assertStringContainsString('Remote: Invalid token.', $dashboard->dashboard());
    }

    public function testErrorIsShownInThePanels(): void {
        $this->skipWithoutApcu();

        $dashboard = $this->apcu(true);
        $this->lastClient()->response = ['error' => 'Invalid token.'];

        $this->assertSame('{"error":"Remote: Invalid token."}', $this->panels($dashboard));
    }

    public function testApcuPanels(): void {
        $this->skipWithoutApcu();

        apcu_store('pu-remote-key', 'value');

        $this->assertSame($this->panels($this->apcu(false)), $this->panels($this->apcu(true)));
    }

    public function testApcuKeysAreListed(): void {
        $this->skipWithoutApcu();

        apcu_store('pu-remote-key', 'value');

        $this->assertStringContainsString('pu-remote-key', $this->apcu(true)->dashboard());
    }

    public function testApcuKeysHaveNoActions(): void {
        $this->skipWithoutApcu();

        apcu_store('pu-remote-key', 'value');
        $html = $this->apcu(true)->dashboard();

        $this->assertStringNotContainsString('delete-key', $html);
        $this->assertStringNotContainsString('view=key', $html);
        $this->assertStringNotContainsString('export_btn', $html);
    }

    /**
     * @throws JsonException
     */
    public function testApcuKeyIsNotDeleted(): void {
        $this->skipWithoutApcu();

        apcu_store('pu-remote-key', 'value');
        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode('pu-remote-key'), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('A remote server can only be monitored.', $this->apcu(true)->ajax());
        $this->assertTrue(apcu_exists('pu-remote-key'));
    }

    public function testApcuKeyValueIsNotShown(): void {
        $this->skipWithoutApcu();

        apcu_store('pu-remote-key', 'pu-secret-value');
        $_GET['view'] = 'key';
        $_GET['key'] = 'pu-remote-key';

        $this->assertStringNotContainsString('pu-secret-value', $this->apcu(true)->dashboard());
    }

    public function testApcuHealth(): void {
        $this->skipWithoutApcu();

        $_GET['tab'] = 'health';
        $local = $this->apcu(false);
        $remote = $this->apcu(true);
        $remote->dashboard(); // Requests the remote server, the checks only read the response.

        $this->assertSame($local->getHealthChecks(), $remote->getHealthChecks());
    }

    public function testOpcachePanels(): void {
        $this->skipWithoutOpcache();

        $local = $this->panels($this->opcache(false));

        $this->assertSame($local, $this->panels($this->opcache(true)));
    }

    public function testOpcacheScriptsAreListed(): void {
        $this->skipWithoutOpcache();

        // Not this file, OPcache skips files changed in the last two seconds (opcache.file_update_protection).
        $_GET['tab'] = 'scripts';
        $_GET['s'] = 'src/Template.php';

        $this->assertStringContainsString('src/Template.php', $this->opcache(true)->dashboard());
    }

    public function testOpcacheScriptsTabMakesOneRequest(): void {
        $this->skipWithoutOpcache();

        $_GET['tab'] = 'scripts';
        $this->opcache(true)->dashboard();

        $this->assertSame(1, $this->lastClient()->requests);
    }

    public function testApcuKeysTabMakesOneRequest(): void {
        $this->skipWithoutApcu();

        $_GET['tab'] = 'keys';
        $this->apcu(true)->dashboard();

        $this->assertSame(1, $this->lastClient()->requests);
    }

    public function testOpcacheHasNoWarmupTab(): void {
        $this->skipWithoutOpcache();

        $this->assertStringNotContainsString('tab=warmup', $this->opcache(true)->dashboard());
    }

    public function testOpcacheIsNotReset(): void {
        $this->skipWithoutOpcache();

        $_GET['deleteall'] = '';

        $this->assertStringContainsString('A remote server can only be monitored.', $this->opcache(true)->ajax());
    }

    public function testOpcacheMoreInfoHasNoScripts(): void {
        $this->skipWithoutOpcache();

        $_GET['tab'] = 'scripts';
        $dashboard = $this->opcache(true);
        $dashboard->dashboard(); // Fetches the scripts first.

        $_GET['tab'] = 'moreinfo';

        $this->assertStringNotContainsString('src/Template.php', $dashboard->dashboard());
    }

    public function testRealpathHealth(): void {
        $_GET['tab'] = 'health';
        $local = $this->realpath(false);
        $remote = $this->realpath(true);
        $remote->dashboard(); // Requests the remote server, the checks only read the response.

        $checks = static fn (array $checks): array => array_map(static fn (array $check): array => [$check['name'], $check['status']], $checks);

        $this->assertSame($checks($local->getHealthChecks()), $checks($remote->getHealthChecks()));
    }

    public function testRealpathEntriesAreListed(): void {
        realpath(__FILE__); // Puts it in the cache.
        $_GET['s'] = 'RemoteServersTest';

        $this->assertStringContainsString(basename(__FILE__), $this->realpath(true)->dashboard());
    }

    public function testRealpathIsNotCleared(): void {
        $_GET['deleteall'] = '';

        $this->assertStringContainsString('A remote server can only be monitored.', $this->realpath(true)->ajax());
    }
}
