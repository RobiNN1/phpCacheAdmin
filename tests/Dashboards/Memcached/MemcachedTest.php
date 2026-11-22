<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Memcached;

use Iterator;
use JsonException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionException;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;
use RobiNN\Pca\Dashboards\Memcached\MemcachedMetrics;
use RobiNN\Pca\Dashboards\Memcached\PHPMem;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class MemcachedTest extends TestCase {
    private MemcachedDashboard $dashboard;

    private PHPMem $memcached;

    /**
     * @throws DashboardException
     */
    protected function setUp(): void {
        $this->dashboard = new MemcachedDashboard(new Template());
        $this->memcached = $this->dashboard->connect([
            'host' => Config::get('memcached.0.host'),
            'port' => Config::get('memcached.0.port'),
        ]);
        $this->dashboard->memcached = $this->memcached;
    }

    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        putenv('PCA_CONSOLE');
        putenv('PCA_MEMCACHEDOPTIONS_BLOCKEDCOMMANDS');
        Config::reset();

        @unlink($this->consoleHistoryFile());
    }

    private function consoleHistoryFile(): string {
        $name = 'memcached_history_'.md5(Helpers::getServerTitle(Config::get('memcached.0')).Config::get('hash', 'pca')).'.json';

        return Config::get('tmpdir', dirname(__DIR__, 3).'/tmp').'/console/'.$name;
    }

    /**
     * @throws MemcachedException
     */
    private function skipBelowMemcached(string $version, string $feature): void {
        if (version_compare($this->memcached->version(), $version, '<')) {
            self::markTestSkipped($feature.' requires Memcached >= '.$version.', the server runs '.$this->memcached->version().'.');
        }
    }

    /**
     * @param array<int, string>|string $keys
     */
    private function deleteMemcachedKeys(array|string $keys): void {
        $this->deleteKeysHelper($keys, fn (string $key): bool => $this->memcached->delete($key));
    }

    public function testIsConnected(): void {
        $this->assertTrue($this->memcached->isConnected());
    }

    /**
     * @throws MemcachedException|JsonException
     */
    public function testAjaxPanels(): void {
        $_GET['panels'] = '';

        $panels = $this->dashboard->ajax();

        $this->assertJson($panels);
        $this->assertStringNotContainsString('"error"', $panels);
    }

    /**
     * @throws MemcachedException|JsonException
     */
    public function testAjaxViewKey(): void {
        $key = 'pu:test:ajax:view';
        $this->memcached->set($key, 'view-data');

        $_GET['view'] = 'key';
        $_GET['key'] = $key;

        $rendered = $this->dashboard->ajax();

        $this->assertStringContainsString($key, $rendered);
        $this->assertStringContainsString('view-data', $rendered);

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException|JsonException
     */
    public function testAjaxDeleteKeyWithInvalidCsrf(): void {
        $key = 'pu:test:ajax';
        $this->memcached->set($key, 'data');

        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);
        $this->setCsrfToken(false);

        $this->assertSame(Helpers::alert('Invalid CSRF token.', 'error'), $this->dashboard->ajax());
        $this->assertTrue($this->memcached->exists($key));

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException|JsonException
     */
    public function testAjaxDeleteKey(): void {
        $key = 'pu:test:ajax';
        $this->memcached->set($key, 'data');

        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);
        $this->setCsrfToken();

        $this->assertSame(
            Helpers::alert(sprintf('Key "%s" has been deleted.', $key), 'success'),
            $this->dashboard->ajax()
        );
        $this->assertFalse($this->memcached->exists($key));
    }

    private function metricsDb(string $server_name): string {
        $dir = Config::get('metricsdir', dirname(__DIR__, 3).'/tmp/metrics');

        return $dir.'/memcached_metrics_'.md5($server_name.Config::get('hash', 'pca')).'.db';
    }

    /**
     * @throws JsonException
     */
    public function testMetrics(): void {
        $server_name = 'pu-metrics-'.uniqid('', true);
        $metrics = new MemcachedMetrics($this->memcached, [['name' => $server_name]], 0);

        $data = json_decode($metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertArrayHasKey('hit_rates', $data[0]);
        $this->assertArrayHasKey('request_rates', $data[0]);
        $this->assertArrayHasKey('memory_used', $data[0]);

        $data = json_decode($metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);

        @unlink($this->metricsDb($server_name));
    }

    /**
     * @throws JsonException|MemcachedException
     */
    public function testMetricsRequestRateOfACommandWithoutCounter(): void {
        $server_name = 'pu-metrics-'.uniqid('', true);
        $metrics = new MemcachedMetrics($this->memcached, [['name' => $server_name]], 0);
        $metrics->collectAndRespond();

        $db = $this->metricsDb($server_name);
        (new PDO('sqlite:'.$db))->exec('UPDATE metrics SET timestamp = '.(time() - 100).', cumulative_requests_delete = 0');

        $this->memcached->set('pu-metrics-delete', 'value');
        $this->memcached->delete('pu-metrics-delete');
        $this->memcached->delete('pu-metrics-missing');

        $data = json_decode($metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(0, end($data)['request_rates']['delete']);

        @unlink($db);
    }

    /**
     * @throws JsonException|MemcachedException
     */
    public function testMetricsLegacyRowsWithoutTheRequestColumns(): void {
        $server_name = 'pu-metrics-'.uniqid('', true);
        $metrics = new MemcachedMetrics($this->memcached, [['name' => $server_name]], 0);
        $metrics->collectAndRespond();

        $db = $this->metricsDb($server_name);
        (new PDO('sqlite:'.$db))->exec('UPDATE metrics SET timestamp = '.(time() - 100).', cumulative_requests_delete = NULL');

        $this->memcached->delete('pu-metrics-missing');

        $data = json_decode($metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertEquals(0, end($data)['request_rates']['delete']);

        @unlink($db);
    }

    /**
     * @throws MemcachedException
     */
    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        $this->memcached->set($key, 'data');
        $this->deleteMemcachedKeys($key);
        $this->assertFalse($this->memcached->exists($key));
    }

    /**
     * @throws MemcachedException
     */
    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        $this->memcached->set($key1, 'data1');
        $this->memcached->set($key2, 'data2');
        $this->memcached->set($key3, 'data3');

        $this->deleteMemcachedKeys([$key1, $key2, $key3]);

        $this->assertFalse($this->memcached->exists($key1));
        $this->assertFalse($this->memcached->exists($key2));
        $this->assertFalse($this->memcached->exists($key3));
    }

    /**
     * @throws MemcachedException
     */
    #[DataProvider('keysProvider')]
    public function testSetGetKey(string $type, mixed $original, mixed $expected): void {
        $this->memcached->set('pu-test-'.$type, $original);
        $this->assertSame($expected, Helpers::mixedToString($this->memcached->get('pu-test-'.$type)));
        $this->memcached->delete('pu-test-'.$type);
    }

    /**
     * @throws MemcachedException
     */
    public function testSaveKey(): void {
        $key = 'pu-test-save';

        $_POST['key'] = $key;
        $_POST['value'] = 'test-value';
        $_POST['encoder'] = 'none';

        Http::stopRedirect();
        $this->dashboard->saveKey();

        $this->assertSame('test-value', $this->memcached->get($key));

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testGetServerStats(): void {
        $this->assertArrayHasKey('version', $this->memcached->getServerStats());
    }

    /**
     * @throws MemcachedException
     */
    public function testGetAllKeysSearchWithEncodedName(): void {
        $key = 'pu:colon:search-key';
        $this->memcached->set($key, 'data');

        $_GET['s'] = 'pu:colon';
        $lines = $this->dashboard->getAllKeys();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('key='.urlencode($key), $lines[0]);

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testGetKeyMeta(): void {
        $key = 'pu-test-meta';
        $this->memcached->set($key, 'some-value', 120);

        $meta = $this->memcached->getKeyMeta($key);

        $this->assertGreaterThan(0, $meta['size']);
        $this->assertGreaterThan(110, $meta['exp']);
        $this->assertLessThanOrEqual(121, $meta['exp']);

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testGetKeyMetaWithoutExpiration(): void {
        $key = 'pu-test-meta';
        $this->memcached->set($key, 'some-value');

        $this->assertSame(-1, $this->memcached->getKeyMeta($key)['exp']);

        $this->memcached->delete($key);
    }

    /**
     * @return Iterator<array<int, string>>
     */
    public static function commandDataProvider(): Iterator {
        yield 'test set' => ['STORED', 'set pu-test-rc-set 0 0 3\r\nidk'];
        yield 'test get' => ['VALUE pu-test-rc-set 0 3\r\nidk\r\nEND', 'get pu-test-rc-set'];
        yield 'test delete' => ['DELETED', 'delete pu-test-rc-set'];
        yield 'test add' => ['STORED', 'add pu-test-rc-add 0 0 3\r\nidk'];
        yield 'test replace' => ['STORED', 'replace pu-test-rc-add 0 0 4\r\ntest'];
        yield 'test replaced value' => ['VALUE pu-test-rc-add 0 4\r\ntest\r\nEND', 'get pu-test-rc-add'];
        yield 'test append' => ['STORED', 'append pu-test-rc-add 0 0 2\r\naa'];
        yield 'test appended value' => ['VALUE pu-test-rc-add 0 6\r\ntestaa\r\nEND', 'get pu-test-rc-add'];
        yield 'test prepend' => ['STORED', 'prepend pu-test-rc-add 0 0 2\r\npp'];
        yield 'test prepended value' => ['VALUE pu-test-rc-add 0 8\r\npptestaa\r\nEND', 'get pu-test-rc-add'];
        yield 'test cas set' => ['STORED', 'set pu-test-rc-cas 0 0 5\r\nvalue'];
        yield 'test cas fail (badval)' => ['EXISTS', 'cas pu-test-rc-cas 0 0 6 999\r\nvalue2'];
        yield 'test cas unchanged value' => ['VALUE pu-test-rc-cas 0 5\r\nvalue\r\nEND', 'get pu-test-rc-cas'];
        yield 'test cas miss' => ['NOT_FOUND', 'cas pu-test-rc-cas-miss 0 0 5 123\r\nvalue'];
        yield 'test gat' => ['VALUE pu-test-rc-add 0 8\r\npptestaa\r\nEND', 'gat 700 pu-test-rc-add'];
        yield 'test touch' => ['TOUCHED', 'touch pu-test-rc-add 0'];
        yield 'test set int' => ['STORED', 'set pu-test-rc-int 0 0 1\r\n1'];
        yield 'test set incr' => ['6', 'incr pu-test-rc-int 5'];
        yield 'test set decr' => ['3', 'decr pu-test-rc-int 3'];
        yield 'test ms' => ['HD', 'ms pu-test-rc-ms 1\r\n4'];
        yield 'test mg' => ['VA 1\r\n4', 'mg pu-test-rc-ms v'];
        yield 'test ma' => ['HD', 'ma pu-test-rc-ms'];
        yield 'test md' => ['HD', 'md pu-test-rc-ms'];
        yield 'test cache_memlimit' => ['OK', 'cache_memlimit 100'];
        yield 'test verbosity' => ['OK', 'verbosity 1'];
        yield 'test flush_all' => ['OK', 'flush_all'];
        yield 'test mn' => ['MN', 'mn'];
        yield 'test quit' => ['', 'quit'];
    }

    /**
     * @throws MemcachedException
     */
    #[DataProvider('commandDataProvider')]
    public function testRunCommand(string $expected, string $command): void {
        $required = ['gat' => '1.5.3', 'gats' => '1.5.3', 'mg' => '1.6.0', 'ms' => '1.6.0', 'md' => '1.6.0', 'ma' => '1.6.0', 'mn' => '1.6.0'];
        $name = (string) strtok($command, ' ');

        if (isset($required[$name])) {
            $this->skipBelowMemcached($required[$name], 'The "'.$name.'" command');
        }

        $command = strtr($command, ['\r\n' => "\r\n"]);
        $this->assertSame(strtr($expected, ['\r\n' => "\r\n"]), $this->memcached->runCommand($command));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function ajaxJson(): array {
        return json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function consoleAjax(string $command): array {
        $_GET['console'] = '';
        $_POST['command'] = $command;

        return $this->ajaxJson();
    }

    /**
     * @throws JsonException
     */
    public function testConsoleAjaxWithInvalidCsrf(): void {
        $this->setCsrfToken(false);

        $this->assertSame('Invalid CSRF token.', $this->consoleAjax('version')['error']);
    }

    /**
     * @throws JsonException|MemcachedException
     */
    public function testConsoleAjaxExecutesCommands(): void {
        $this->setCsrfToken();

        $this->assertStringStartsWith('VERSION', $this->consoleAjax('version')['output']);

        $this->assertSame('STORED', $this->consoleAjax('set pu-console 0 0 3\nabc')['output']);
        $this->assertStringContainsString('abc', (string) $this->consoleAjax('get pu-console')['output']);

        $this->memcached->delete('pu-console');
    }

    /**
     * @throws JsonException
     */
    public function testConsoleAjaxUnknownCommand(): void {
        $this->setCsrfToken();

        $this->assertArrayHasKey('error', $this->consoleAjax('notacommand'));
    }

    /**
     * @throws JsonException
     */
    public function testConsoleAjaxBlockedCommand(): void {
        $this->setCsrfToken();

        $response = $this->consoleAjax('shutdown');

        $this->assertStringContainsString('not allowed', (string) $response['error']);
        $this->assertArrayNotHasKey('tab', $response); // there is no tab to send the user to
    }

    /**
     * @throws JsonException
     */
    public function testConsoleAjaxBlockedCommandSuggestsTab(): void {
        $this->setCsrfToken();

        $response = $this->consoleAjax('watch fetchers');

        $this->assertStringContainsString('not allowed', (string) $response['error']);
        $this->assertStringContainsString('tab=watcher', (string) $response['tab']['url']);
        $this->assertStringContainsString('Watcher', (string) $response['tab']['label']);
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('consoleCommandTabProvider')]
    public function testConsoleAjaxAllowedCommandSuggestsTab(string $command, string $tab, string $label): void {
        $this->setCsrfToken();

        $response = $this->consoleAjax($command);

        $this->assertArrayHasKey('output', $response);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertStringContainsString('tab='.$tab, (string) $response['tab']['url']);
        $this->assertSame('See it formatted on the '.$label.' tab', $response['tab']['label']);
    }

    /**
     * @return Iterator<string, array{0: string, 1: string, 2: string}>
     */
    public static function consoleCommandTabProvider(): Iterator {
        yield 'stats' => ['stats', 'moreinfo', 'More info'];
        yield 'stats items' => ['stats items', 'items', 'Items'];
        yield 'stats slabs' => ['stats slabs', 'slabs', 'Slabs'];
        yield 'stats sizes' => ['stats sizes', 'analysis', 'Analysis'];
        yield 'stats conns' => ['stats conns', 'connections', 'Connections'];
        // A two-word command wins over the one-word entry it starts with.
        yield 'stats settings' => ['stats settings', 'moreinfo', 'More info'];
    }

    /**
     * @throws JsonException
     */
    public function testConsoleBlocksExtraCommandsFromTheConfig(): void {
        putenv('PCA_MEMCACHEDOPTIONS_BLOCKEDCOMMANDS=["stats reset"]');
        Config::reset();

        $this->setCsrfToken();

        $this->assertStringContainsString('not allowed', (string) $this->consoleAjax('stats reset')['error']);
        $this->assertArrayHasKey('output', $this->consoleAjax('stats')); // Only the subcommand is refused.
    }

    /**
     * @throws JsonException
     */
    public function testConsoleAjaxCommandWithoutATab(): void {
        $this->setCsrfToken();

        $this->assertArrayNotHasKey('tab', $this->consoleAjax('version'));
    }

    /**
     * @throws JsonException
     */
    public function testConsoleAjaxRejectsEmptyCommand(): void {
        $this->setCsrfToken();

        $this->assertSame('Empty command.', $this->consoleAjax('   ')['error']);
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('smuggledCommandProvider')]
    public function testConsoleRefusesASecondCommandOnItsOwnLine(string $command): void {
        putenv('PCA_MEMCACHEDOPTIONS_BLOCKEDCOMMANDS=["flush_all"]');
        Config::reset();

        $this->setCsrfToken();

        $response = $this->consoleAjax($command);

        $this->assertStringContainsString('Only a storage command can be followed by a value', (string) $response['error']);
        $this->assertArrayNotHasKey('output', $response);
    }

    /**
     * @return Iterator<string, array{0: string}>
     */
    public static function smuggledCommandProvider(): Iterator {
        yield 'escaped newline' => ['version\nflush_all'];
        yield 'escaped crlf' => ['version\r\nflush_all'];
        yield 'literal newline' => ["version\nflush_all"];
        yield 'literal crlf' => ["version\r\nflush_all"];
    }

    /**
     * @throws JsonException
     */
    public function testConsoleRefusesAValueThatIsNotTheDeclaredLength(): void {
        $this->setCsrfToken();

        $response = $this->consoleAjax('set pu-smuggle 0 0 1\na\r\nflush_all');

        $this->assertStringContainsString('exactly the number of bytes it declares', (string) $response['error']);
        $this->assertArrayNotHasKey('output', $response);
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('serverTuningCommandProvider')]
    public function testConsoleBlocksServerTuning(string $command): void {
        $this->setCsrfToken();

        $response = $this->consoleAjax($command);

        $this->assertStringContainsString('not allowed', (string) $response['error']);
        $this->assertArrayNotHasKey('output', $response);
    }

    /**
     * @return Iterator<string, array{0: string}>
     */
    public static function serverTuningCommandProvider(): Iterator {
        yield 'memory limit' => ['cache_memlimit 64'];
        yield 'slab rebalancing' => ['slabs automove 1'];
        yield 'lru tuning' => ['lru mode flat'];
        yield 'log verbosity' => ['verbosity 2'];
        yield 'crawler off' => ['lru_crawler disable'];
        yield 'crawler sleep' => ['lru_crawler sleep 0'];
    }

    /**
     * @throws JsonException|MemcachedException
     */
    public function testConsoleCanBeTurnedOff(): void {
        putenv('PCA_CONSOLE=false');
        Config::reset();

        $this->setCsrfToken();
        $this->assertSame('The console is disabled.', $this->consoleAjax('version')['error']);

        $_GET['tab'] = 'console';
        $rendered = (new MemcachedDashboard(new Template()))->dashboard();

        $this->assertStringNotContainsString('>Console<', $rendered);
    }

    /**
     * @throws JsonException
     */
    public function testConsoleHistory(): void {
        @unlink($this->consoleHistoryFile());

        $this->setCsrfToken();

        foreach (['version', 'stats', 'stats'] as $command) {
            $this->consoleAjax($command);
        }

        unset($_POST['command']);
        $_GET['history'] = '';

        $this->assertSame(['version', 'stats'], $this->ajaxJson()['history']);
    }

    /**
     * @throws JsonException
     */
    public function testConsoleHistoryWithInvalidCsrf(): void {
        $_GET['console'] = '';
        $_GET['history'] = '';
        $this->setCsrfToken(false);

        $this->assertSame('Invalid CSRF token.', $this->ajaxJson()['error']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function findRow(array $rows, string $name): array {
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        self::fail(sprintf('No "%s" row in %s.', $name, implode(', ', array_column($rows, 'name'))));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function runAnalysis(): array {
        $this->memcached->flush();

        for ($i = 0; $i < 200; $i++) {
            $this->memcached->set('pu-analysis:cache:page:'.$i, str_repeat('x', 50));
        }

        $this->memcached->set('pu-analysis:zz-blob:huge', str_repeat('x', 50000));
        $this->memcached->set('pu-analysis:ttl:soon', 'value', 300);
        $this->memcached->set('pu-analysis-no-namespace', 'value');

        $lines = $this->memcached->getKeys();

        sort($lines);

        return $this->dashboard->analyzeKeys($lines);
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisSummary(): void {
        $analysis = $this->runAnalysis();

        $this->assertSame(203, $analysis['summary']['analyzed']);
        $this->assertSame(2, $analysis['summary']['namespaces']);
        $this->assertSame(202, $analysis['summary']['no_expiry']['count']);
        $this->assertCount(5, $analysis['tiles']);

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisNeverReadKeys(): void {
        $this->skipBelowMemcached('1.6.0', 'The fetched flag');

        $analysis = $this->runAnalysis();

        // Nothing has read the keys, and the analysis itself must not either.
        $this->assertSame(203, $analysis['summary']['never_read']['count']);

        $this->memcached->set('pu-analysis:read', 'value');
        $this->memcached->runCommand('get pu-analysis:read');

        $analysis = $this->dashboard->analyzeKeys($this->memcached->getKeys());

        $this->assertSame(203, $analysis['summary']['never_read']['count']);
        $this->assertSame(204, $analysis['summary']['analyzed']);

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisSkipsExpiredKeys(): void {
        $this->runAnalysis();

        // On a later server clock the key with a 300 s TTL is gone.
        $analysis = $this->dashboard->analyzeKeys($this->memcached->getKeys(), $this->memcached->serverTime() + 400);

        $this->assertSame(202, $analysis['summary']['analyzed']);
        $this->assertSame(1, $analysis['summary']['expired']);

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisTopKeys(): void {
        $analysis = $this->runAnalysis();

        $this->assertSame('pu-analysis:zz-blob:huge', $analysis['top_memory'][0]['key']);
        // A metadump counts the item overhead in, the fallback for older servers only the value.
        $this->assertGreaterThanOrEqual(50000, $analysis['top_memory'][0]['size']);

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisNamespaces(): void {
        $analysis = $this->runAnalysis();

        $this->assertSame(202, $this->findRow($analysis['namespaces'], 'pu-analysis')['count']);
        $this->assertSame(1, $this->findRow($analysis['namespaces'], '(no namespace)')['count']);

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisExpiry(): void {
        $analysis = $this->runAnalysis();

        $this->assertSame(202, $this->findRow($analysis['expiry'], 'No expiry')['count']);
        $this->assertSame(1, $this->findRow($analysis['expiry'], '< 1 hour')['count']);

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisIdle(): void {
        $this->skipBelowMemcached('1.5.19', 'The last access time');

        $analysis = $this->runAnalysis();

        $this->assertSame(203, $this->findRow($analysis['idle'], '< 1 minute')['count']);

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testGetAllKeysTableView(): void {
        $this->memcached->set('pu-test-table1', 'value1');
        $this->memcached->set('pu-test-table2', 'value2');
        $_GET['s'] = 'pu-test-table';

        $result = $this->dashboard->getAllKeys();

        $info = [
            'bytes_size'           => 0,
            'timediff_last_access' => 0,
            'ttl'                  => 'Doesn\'t expire',
        ];

        $expected = [
            [
                'key'  => 'pu-test-table1',
                'info' => array_merge(['link_title' => 'pu-test-table1'], $info),
            ],
            [
                'key'  => 'pu-test-table2',
                'info' => array_merge(['link_title' => 'pu-test-table2'], $info),
            ],
        ];

        $result = $this->dashboard->keysTableView($result);
        $result = $this->normalizeInfoFields($result, ['bytes_size', 'timediff_last_access']);

        $this->assertEquals($this->sortKeys($expected), $this->sortKeys($result));

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testKeysTableViewDecodesKeys(): void {
        $key = 'pu-test-decode:a&b%c+d/é';
        $this->memcached->set($key, 'value');
        $_GET['s'] = 'pu-test-decode';

        $result = $this->dashboard->keysTableView($this->dashboard->getAllKeys());

        $this->assertCount(1, $result);
        $this->assertSame($key, $result[0]['key']);
        $this->assertSame($key, $result[0]['info']['link_title']);
        $this->assertTrue($this->memcached->exists($result[0]['key']));

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testGetAllKeysTreeView(): void {
        $this->memcached->set('pu-test-tree1:sub1', 'value1');
        $this->memcached->set('pu-test-tree1:sub2', 'value2');
        $this->memcached->set('pu-test-tree2', 'value3');
        $_GET['s'] = 'pu-test-tree';

        $result = $this->dashboard->getAllKeys();

        $info = [
            'bytes_size'           => 0,
            'timediff_last_access' => 0,
            'ttl'                  => 'Doesn\'t expire',
        ];

        $expected = [
            'pu-test-tree1' => [
                'type'     => 'folder',
                'name'     => 'pu-test-tree1',
                'path'     => 'pu-test-tree1',
                'children' => [
                    ['type' => 'key', 'name' => 'sub1', 'key' => 'pu-test-tree1:sub1', 'info' => $info,],
                    ['type' => 'key', 'name' => 'sub2', 'key' => 'pu-test-tree1:sub2', 'info' => $info,],
                ],
                'expanded' => false,
                'count'    => 2,
            ],
            ['type' => 'key', 'name' => 'pu-test-tree2', 'key' => 'pu-test-tree2', 'info' => $info,],
        ];

        $result = $this->dashboard->keysTreeView($result);
        $result = $this->normalizeInfoFields($result, ['bytes_size', 'timediff_last_access']);

        $this->assertEquals($this->sortTreeKeys($expected), $this->sortTreeKeys($result));

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testExportAndImport(): void {
        $keys_to_test = [
            'pu:mem:key1' => ['value' => 'simple-value', 'ttl' => 120],
            'pu:mem:key2' => ['value' => 'no-expire-value', 'ttl' => 0],
            'pu:mem:key3' => ['value' => '{"json": "data"}', 'ttl' => 300],
        ];

        $export_keys_array = [];

        foreach ($keys_to_test as $key => $data) {
            $this->memcached->set($key, $data['value'], $data['ttl']);
            $export_keys_array[] = ['key' => urlencode($key), 'info' => ['ttl' => $data['ttl']]];
        }

        $exported_json = Helpers::export(
            $export_keys_array,
            'memcached_backup',
            function (string $key): ?string {
                $value = $this->memcached->get(urldecode($key));

                return $value !== false ? base64_encode($value) : null;
            },
            true
        );

        $this->memcached->flush();

        foreach (array_keys($keys_to_test) as $key) {
            $this->assertFalse($this->memcached->exists($key));
        }

        $tmp_file_path = tempnam(sys_get_temp_dir(), 'pu-');
        file_put_contents($tmp_file_path, $exported_json);

        $_FILES['import'] = [
            'name'     => 'test_import.json',
            'type'     => 'application/json',
            'tmp_name' => $tmp_file_path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp_file_path),
        ];

        Http::stopRedirect();

        Helpers::import(
            fn (string $key): bool => $this->memcached->exists($key),
            fn (string $key, string $value, int $ttl): bool => $this->memcached->set(urldecode($key), base64_decode($value), $ttl),
            true
        );

        foreach ($keys_to_test as $key => $data) {
            $this->assertTrue($this->memcached->exists($key));
            $this->assertSame($data['value'], $this->memcached->get($key));
            $this->memcached->delete($key);
        }

        unlink($tmp_file_path);
    }

    /**
     * @throws MemcachedException
     * @throws ReflectionException
     */
    public function testExportAndImportKeepFlags(): void {
        $key = 'pu:mem:flags';
        $this->memcached->set($key, 'a:0:{}', 0, 4);
        $_GET['s'] = 'pu:mem:flags';
        $_GET['export_btn'] = '1';

        $exported = Helpers::export(
            [['key' => urlencode($key), 'ttl' => -1]],
            'memcached_backup',
            fn (string $key): ?array => $this->callPrivate($this->dashboard, 'exportValue', urldecode($key)),
            true
        );

        $this->assertStringContainsString('"flags":4', (string) $exported);

        $this->memcached->delete($key);

        $tmp_file_path = (string) tempnam(sys_get_temp_dir(), 'pu-');
        file_put_contents($tmp_file_path, (string) $exported);
        $_FILES['import'] = ['name' => 'i.json', 'type' => 'application/json', 'tmp_name' => $tmp_file_path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp_file_path)];

        Helpers::import(
            fn (string $key): bool => $this->memcached->exists($key),
            fn (string $key, string $value, int $ttl, array $data = []): bool => $this->memcached->set(urldecode($key), base64_decode($value), $ttl, (int) ($data['flags'] ?? 0)),
            true
        );

        $this->assertSame(4, $this->memcached->getItem($key)['flags'] ?? null);

        $this->memcached->delete($key);
        unlink($tmp_file_path);
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array<int, string> Matching keys.
     *
     * @throws MemcachedException
     */
    private function filteredKeys(array $filters): array {
        $_GET = $filters;

        $keys = array_map(fn (string $line): string => urldecode((string) $this->memcached->parseLine($line)['key']), $this->dashboard->getAllKeys());
        sort($keys);

        return $keys;
    }

    /**
     * @throws MemcachedException
     */
    public function testKeyFilters(): void {
        $this->skipBelowMemcached('1.5.19', 'Key filters');

        $this->memcached->set('pu-filter:small', 'x');
        $this->memcached->set('pu-filter:big', str_repeat('x', 5000));
        $this->memcached->set('pu-filter:ttl', 'x', 60);
        $this->memcached->set('pu-filter:read', 'x');
        $this->memcached->runCommand('get pu-filter:read');
        $this->memcached->set('other:pu-filter', 'x');

        $this->assertSame(['other:pu-filter', 'pu-filter:big', 'pu-filter:read', 'pu-filter:small', 'pu-filter:ttl'], $this->filteredKeys(['s' => 'pu-filter']));
        $this->assertSame(['pu-filter:big', 'pu-filter:read', 'pu-filter:small', 'pu-filter:ttl'], $this->filteredKeys(['s' => 'pu-filter:', 'match' => 'prefix']));
        $this->assertSame(['pu-filter:big', 'pu-filter:small'], $this->filteredKeys(['s' => '^pu-filter:(big|small)$', 'match' => 'regex']));
        $this->assertSame(['pu-filter:big'], $this->filteredKeys(['s' => 'pu-filter', 'minsize' => '1000']));
        $this->assertSame(['pu-filter:ttl'], $this->filteredKeys(['s' => 'pu-filter', 'expin' => '120']));
        $this->assertSame(['other:pu-filter', 'pu-filter:big', 'pu-filter:read', 'pu-filter:small'], $this->filteredKeys(['s' => 'pu-filter', 'noexp' => 'on']));

        if (version_compare($this->memcached->version(), '1.6.0', '>=')) {
            $this->assertNotContains('pu-filter:read', $this->filteredKeys(['s' => 'pu-filter', 'nf' => 'on']));
            $this->assertContains('pu-filter:small', $this->filteredKeys(['s' => 'pu-filter', 'nf' => 'on']));
        }

        $this->memcached->flush();
    }

    /**
     * @throws MemcachedException
     */
    public function testInvalidRegexMatchesNothing(): void {
        $this->memcached->set('pu-regex', 'x');

        $this->assertSame([], $this->filteredKeys(['s' => '(', 'match' => 'regex']));

        $this->memcached->delete('pu-regex');
    }

    /**
     * @throws MemcachedException
     * @throws ReflectionException
     */
    public function testDeleteMatchingKeys(): void {
        $this->skipBelowMemcached('1.5.19', 'Key filters');

        for ($i = 0; $i < 30; $i++) {
            $this->memcached->set('pu-bulk:'.$i, 'x');
        }

        $this->memcached->set('pu-keep', 'x');

        $_GET = ['s' => 'pu-bulk:', 'match' => 'prefix'];

        $this->assertStringContainsString('30 matching keys have been deleted', (string) $this->callPrivate($this->dashboard, 'deleteMatchingKeys'));
        $this->assertFalse($this->memcached->exists('pu-bulk:0'));
        $this->assertFalse($this->memcached->exists('pu-bulk:29'));
        $this->assertTrue($this->memcached->exists('pu-keep'));

        $this->memcached->delete('pu-keep');
    }

    /**
     * @throws MemcachedException
     * @throws ReflectionException
     */
    public function testDeleteMatchingKeysNeedsAFilter(): void {
        $this->memcached->set('pu-keep', 'x');

        $this->assertStringContainsString('Set a search or a filter first', (string) $this->callPrivate($this->dashboard, 'deleteMatchingKeys'));
        $this->assertTrue($this->memcached->exists('pu-keep'));

        $this->memcached->delete('pu-keep');
    }

    /**
     * @throws MemcachedException
     */
    public function testGetItem(): void {
        $key = 'pu-item';
        $this->memcached->set($key, "multi\r\nEND\r\nline", 120, 52);

        $item = $this->memcached->getItem($key);

        $this->assertNotNull($item);
        $this->assertSame("multi\r\nEND\r\nline", $item['value']);
        $this->assertSame(52, $item['flags']);
        $this->assertGreaterThan(110, $item['exp']);
        $this->assertNull($this->memcached->getItem('pu-item-missing'));

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testViewingAKeyDoesNotMarkItAsFetched(): void {
        $this->skipBelowMemcached('1.6.0', 'Meta commands');

        $key = 'pu-not-fetched';
        $this->memcached->set($key, 'value');

        $_GET['view'] = 'key';
        $_GET['key'] = $key;
        $this->dashboard->ajax();
        $this->memcached->exists($key);
        $this->memcached->get($key);

        $this->assertFalse($this->memcached->getKeyMeta($key)['fetch']);

        $this->memcached->runCommand('get '.$key);

        $this->assertTrue($this->memcached->getKeyMeta($key)['fetch']);

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testTouch(): void {
        $key = 'pu-touch';
        $this->memcached->set($key, 'value', 60);

        $this->assertTrue($this->memcached->touch($key, 500));
        $this->assertGreaterThan(400, $this->memcached->getKeyMeta($key)['exp']);
        $this->assertSame('value', $this->memcached->get($key));

        $this->assertTrue($this->memcached->touch($key, 0));
        $this->assertSame(-1, $this->memcached->getKeyMeta($key)['exp']);
        $this->assertFalse($this->memcached->touch('pu-touch-missing', 10));

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testChangeTtlInKeyView(): void {
        $key = 'pu-change-ttl';
        $this->memcached->set($key, 'value');

        $_GET['view'] = 'key';
        $_GET['key'] = $key;
        $_POST['change_ttl'] = '';
        $_POST['ttl'] = '300';
        $this->setCsrfToken();
        Http::stopRedirect();

        $this->dashboard->ajax();

        $this->assertGreaterThan(200, $this->memcached->getKeyMeta($key)['exp']);

        $this->memcached->delete($key);
    }

    /**
     * Memcached reads an expiration over 30 days as a timestamp, a long TTL must not expire the key at once.
     *
     * @throws MemcachedException
     */
    public function testLongTtl(): void {
        $key = 'pu-long-ttl';
        $ttl = 40 * 86400;
        $this->memcached->set($key, 'value', $ttl);

        $this->assertTrue($this->memcached->exists($key));
        $this->assertGreaterThan($ttl - 10, $this->memcached->getKeyMeta($key)['exp']);

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testDeleteMany(): void {
        $keys = [];

        for ($i = 0; $i < 1200; $i++) {
            $keys[] = 'pu-many:'.$i;
            $this->memcached->set('pu-many:'.$i, 'x');
        }

        $this->assertSame(1200, $this->memcached->deleteMany($keys));
        $this->assertFalse($this->memcached->exists('pu-many:0'));
        $this->assertFalse($this->memcached->exists('pu-many:1199'));
        $this->assertTrue($this->memcached->isConnected());
    }

    /**
     * @throws MemcachedException
     */
    public function testSaveKeyKeepsFlags(): void {
        $key = 'pu-save-flags';

        $_POST = ['key' => $key, 'value' => 'a:0:{}', 'encoder' => 'none', 'flags' => '4', 'expire' => '0'];

        Http::stopRedirect();
        $this->dashboard->saveKey();

        $this->assertSame(4, $this->memcached->getItem($key)['flags'] ?? null);

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     * @throws ReflectionException
     */
    public function testEditFormDecompressesAndDropsTheCompressionBits(): void {
        $key = 'pu-form-compressed';
        $original = str_repeat('compress me ', 50);
        $this->memcached->set($key, pack('V', strlen($original)).gzcompress($original), 0, 4 | 16 | 32);

        $_GET['form'] = 'edit';
        $_GET['key'] = $key;

        $form = (string) $this->callPrivate($this->dashboard, 'form');

        $this->assertStringContainsString(trim($original), $form);
        $this->assertMatchesRegularExpression('/name="flags"[^>]*value="4"/', $form);

        $this->memcached->delete($key);
    }

    public function testReadableStats(): void {
        $stats = $this->dashboard->readableStats(['limit_maxbytes' => 67_108_864, 'uptime' => 90061, 'curr_items' => 5, 'bytes' => 10]);

        $this->assertStringContainsString('67108864 (', (string) $stats['limit_maxbytes']);
        $this->assertStringContainsString('1 day 1 hour 1 minute 1 second', (string) $stats['uptime']);
        $this->assertSame(5, $stats['curr_items']);
        $this->assertSame(10, $stats['bytes']);
    }

    public function testParseConnections(): void {
        $connections = $this->dashboard->parseConnections([
            'curr_connections'       => 3, // "stats conns" has no such field, but nothing may break on one
            '25:addr'                => 'tcp:0.0.0.0:11211',
            '25:state'               => 'conn_listening',
            '25:secs_since_last_cmd' => 124_865,
            '27:addr'                => 'tcp:127.0.0.1:53054',
            '27:listen_addr'         => 'tcp:127.0.0.1:11211',
            '27:state'               => 'conn_parse_cmd',
            '27:secs_since_last_cmd' => 0,
        ]);

        $this->assertCount(2, $connections);

        $this->assertSame(25, $connections[0]['fd']);
        $this->assertTrue($connections[0]['listening']);
        // A listening socket was not accepted on another one.
        $this->assertSame('', $connections[0]['listen_addr']);

        $this->assertSame('tcp:127.0.0.1:53054', $connections[1]['addr']);
        $this->assertSame('tcp:127.0.0.1:11211', $connections[1]['listen_addr']);
        $this->assertSame(0, $connections[1]['idle']);
        $this->assertFalse($connections[1]['listening']);
    }

    /**
     * @throws MemcachedException
     */
    public function testConnectionsTab(): void {
        $_GET['tab'] = 'connections';

        $rendered = $this->dashboard->dashboard();

        $this->assertStringContainsString('Connections', $rendered);
        // The dashboard's own connection is always in the list.
        $this->assertStringContainsString('conn_', $rendered);
    }

    public function testSizeDistribution(): void {
        $sizes = $this->dashboard->sizeDistribution(['96' => 3, '288' => 1, '5088' => 4]);

        $this->assertTrue($sizes['enabled']);
        $this->assertSame(8, $sizes['total']);
        $this->assertSame([96, 288, 5088], array_column($sizes['rows'], 'name'));
        $this->assertEqualsWithDelta(37.5, $sizes['rows'][0]['percent'], PHP_FLOAT_EPSILON);
    }

    public function testSizeDistributionWithoutTrackSizes(): void {
        $sizes = $this->dashboard->sizeDistribution(['sizes_status' => 'disabled']);

        $this->assertFalse($sizes['enabled']);
        $this->assertSame([], $sizes['rows']);
    }

    public function testParseWatchLine(): void {
        $log = $this->dashboard->parseWatchLine(
            'ts=1786477627.620824 gid=4 type=item_store key=foo%3Abar status=stored cmd=set ttl=0 clsid=1 cfd=28 size=5'
        );

        $this->assertSame(4, $log['gid']);
        $this->assertSame('item_store', $log['type']);
        // Keys are logged urlencoded.
        $this->assertSame('foo:bar', $log['key']);
        $this->assertEqualsWithDelta(1786477627.620824, $log['time'], 0.000001);
        $this->assertSame(['status' => 'stored', 'cmd' => 'set', 'ttl' => '0', 'clsid' => '1', 'cfd' => '28', 'size' => '5'], $log['fields']);
    }

    public function testParseWatchLineOfAConnectionEvent(): void {
        $log = $this->dashboard->parseWatchLine('ts=1786477627.620815 gid=2 type=conn_new rip=127.0.0.1 rport=53051 transport=tcp cfd=28');

        $this->assertSame('conn_new', $log['type']);
        $this->assertSame('', $log['key']);
        $this->assertSame('127.0.0.1', $log['fields']['rip']);
    }

    #[DataProvider('invalidWatchLineProvider')]
    public function testParseWatchLineWithInvalidInput(string $line): void {
        $this->assertNull($this->dashboard->parseWatchLine($line));
    }

    /**
     * @return Iterator<string, array{0: string}>
     */
    public static function invalidWatchLineProvider(): Iterator {
        yield 'empty line' => [''];
        yield 'the OK of the watch command' => ['OK'];
        yield 'no timestamp' => ['gid=4 type=item_store key=foo'];
        yield 'no type' => ['ts=1786477627.620824 gid=4 key=foo'];
    }

    /**
     * @throws MemcachedException
     */
    public function testWatcherCapture(): void {
        $this->skipBelowMemcached('1.4.26', 'Watchers');

        $key = 'pu-watcher-key';
        $script = sprintf(
            'require %s; usleep(300000); $m = new PHPMem(%s); $m->set(%s, "watched"); $m->get(%s); $m->delete(%s);',
            var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
            var_export(['host' => Config::get('memcached.0.host'), 'port' => Config::get('memcached.0.port')], true),
            var_export($key, true),
            var_export($key, true),
            var_export($key, true)
        );

        exec(sprintf('%s -r %s > /dev/null 2>&1 &', escapeshellarg(PHP_BINARY), escapeshellarg('use '.PHPMem::class.'; '.$script)));

        $logs = $this->dashboard->captureLogs(['fetchers', 'mutations'], 2, 100);

        $this->assertNotEmpty($logs);

        $types = static fn (string $type): array => array_values(array_filter(
            $logs,
            static fn (array $log): bool => $log['type'] === $type && $log['key'] === $key
        ));

        $this->assertCount(1, $types('item_store'));
        $this->assertNotEmpty($types('item_get'));
        $this->assertGreaterThan(0, $types('item_store')[0]['time']);
    }

    /**
     * @throws MemcachedException
     */
    public function testWatcherCaptureHonorsTheLimit(): void {
        $this->skipBelowMemcached('1.4.26', 'Watchers');

        $script = sprintf(
            'require %s; usleep(300000); $m = new PHPMem(%s); for ($i = 0; $i < 200; $i++) { $m->set("pu-watcher-flood-".$i, "v"); }',
            var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
            var_export(['host' => Config::get('memcached.0.host'), 'port' => Config::get('memcached.0.port')], true)
        );

        exec(sprintf('%s -r %s > /dev/null 2>&1 &', escapeshellarg(PHP_BINARY), escapeshellarg('use '.PHPMem::class.'; '.$script)));

        $this->assertCount(5, $this->dashboard->captureLogs(['mutations'], 3, 5));

        for ($i = 0; $i < 200; $i++) {
            $this->memcached->delete('pu-watcher-flood-'.$i);
        }
    }

    /**
     * @throws MemcachedException
     */
    public function testWatcherRejectsAnUnknownMode(): void {
        $this->skipBelowMemcached('1.4.26', 'Watchers');

        $this->expectException(MemcachedException::class);
        $this->expectExceptionMessageMatches('/refused to watch "nonsense"/');

        $this->dashboard->captureLogs(['nonsense'], 1, 5);
    }

    public function testWatcherModesDependOnTheServerVersion(): void {
        $this->assertSame(['fetchers', 'mutations', 'evictions'], array_keys($this->dashboard->watcherModes('1.4.33')));
        $this->assertSame(['fetchers', 'mutations', 'evictions', 'connevents'], array_keys($this->dashboard->watcherModes('1.6.11')));
        $this->assertSame(['fetchers', 'mutations', 'evictions', 'connevents', 'deletions'], array_keys($this->dashboard->watcherModes('1.6.20')));
        $this->assertSame([], $this->dashboard->watcherModes('1.4.25'));
    }

    /**
     * @throws JsonException
     */
    public function testWatcherAjaxWithoutModes(): void {
        $_GET['watcher'] = '';
        $this->setCsrfToken();

        $this->assertSame('Pick at least one thing to watch.', $this->ajaxJson()['error']);
    }

    /**
     * @throws JsonException
     */
    public function testWatcherAjaxWithInvalidCsrf(): void {
        $_GET['watcher'] = '';
        $_POST['modes'] = 'mutations';
        $this->setCsrfToken(false);

        $this->assertSame('Invalid CSRF token.', $this->ajaxJson()['error']);
    }

    /**
     * @throws JsonException|MemcachedException
     */
    public function testWatcherAjaxCapture(): void {
        $this->skipBelowMemcached('1.4.26', 'Watchers');

        $_GET['watcher'] = '';
        // Only known names are watched, the rest is dropped.
        $_POST['modes'] = 'mutations,rm -rf';
        $_POST['window'] = 1;
        $this->setCsrfToken();

        $data = $this->ajaxJson();

        $this->assertArrayHasKey('logs', $data);
        $this->assertArrayHasKey('skipped', $data);
    }

    /**
     * @throws MemcachedException
     */
    public function testWatcherTab(): void {
        $this->skipBelowMemcached('1.4.26', 'Watchers');

        $_GET['tab'] = 'watcher';

        $rendered = $this->dashboard->dashboard();

        $this->assertStringContainsString('watcher_toggle', $rendered);
        $this->assertStringContainsString('Evictions', $rendered);
    }

    /**
     * Memcached >= 1.6.43 prefixes a metadump with an "OK" line, which must not end the response.
     */
    public function testOkDoesNotTerminateAMetadump(): void {
        $this->assertFalse($this->memcached->isEndOfResponse('OK', false));
        $this->assertTrue($this->memcached->isEndOfResponse('END', false));
    }

    public function testOkTerminatesAShortReply(): void {
        $this->assertTrue($this->memcached->isEndOfResponse('OK'));
    }

    /**
     * A textarea would replace the bytes that are not text, saving would corrupt the value.
     *
     * @throws MemcachedException
     * @throws ReflectionException
     */
    public function testBinaryValueCannotBeEdited(): void {
        $key = 'pu-binary';
        $this->memcached->set($key, "\x00\x01\xff\xfe binary \x80");

        $_GET = ['view' => 'key', 'key' => $key];
        $view = $this->dashboard->ajax();

        $this->assertStringContainsString('Binary value, it cannot be edited as text.', $view);
        $this->assertStringNotContainsString('form=edit', $view);

        $_GET = ['form' => 'edit', 'key' => $key];
        $form = (string) $this->callPrivate($this->dashboard, 'form');

        $this->assertStringContainsString('This value is binary data', $form);
        $this->assertStringNotContainsString('<textarea', $form);

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testAnalysisDistributions(): void {
        $this->skipBelowMemcached('1.6.0', 'Flags and slab classes in the metadump');

        $this->runAnalysis();
        $this->memcached->set('pu-analysis:flagged', 'a:0:{}', 0, 4);
        $analysis = $this->dashboard->analyzeKeys($this->memcached->getKeys());

        $this->assertSame(204, array_sum(array_column($analysis['sizes'], 'count')));
        $this->assertSame(0, $this->findRow($analysis['sizes'], '1 MB +')['count']); // nothing is that big
        $this->assertSame(1, $this->findRow($analysis['flags'], '4')['count']);
        $this->assertStringContainsString('PHP serialized', (string) $this->findRow($analysis['flags'], '4')['meaning']);
        $this->assertSame(204, array_sum(array_column($analysis['classes'], 'count')));
        $this->assertSame('pu-analysis', $analysis['namespaces_count'][0]['name']);

        $this->memcached->flush();
    }

    public function testCommandRates(): void {
        [$rates, $baseline] = $this->dashboard->ratesSince(['get' => 1000], 100, null, 1000.0);
        $this->assertNull($rates);
        $this->assertTrue($baseline);
        $this->assertStringNotContainsString('now', $this->dashboard->rateLabels(['get' => 1000], 100, $rates)['get']);
        $this->assertStringStartsWith('10', $this->dashboard->rateLabels(['get' => 1000], 100, $rates)['get']); // the average

        $previous = ['time' => 1000.0, 'uptime' => 100, 'counts' => ['get' => 1000], 'rates' => null];
        [$rates, $baseline] = $this->dashboard->ratesSince(['get' => 1500], 110, $previous, 1010.0);
        $this->assertEqualsWithDelta(50.0, $rates['get'] ?? 0, 0.001);
        $this->assertTrue($baseline);
        $this->assertStringContainsString('/s now', $this->dashboard->rateLabels(['get' => 1500], 110, $rates)['get']);

        // Too soon after the previous sample, its rates are kept and so is the baseline.
        [$rates, $baseline] = $this->dashboard->ratesSince(['get' => 1501], 110, ['rates' => ['get' => 50.0]] + $previous, 1000.2);
        $this->assertEqualsWithDelta(50.0, $rates['get'] ?? 0, 0.001);
        $this->assertFalse($baseline);

        // After a restart the counters start again, there is nothing to compare with.
        [$rates] = $this->dashboard->ratesSince(['get' => 5], 3, $previous, 1010.0);
        $this->assertNull($rates);
    }

    /**
     * @throws ReflectionException
     */
    public function testConnectionsSummary(): void {
        $tab = $this->callPrivate($this->dashboard, 'connectionsTab');

        $this->assertIsArray($tab);
        $this->assertArrayHasKey('states', $tab);
        $this->assertSame(count($tab['connections']), array_sum($tab['states']));
        $this->assertLessThanOrEqual(count($tab['connections']), array_sum($tab['hosts'])); // the top 10 hosts
        $this->assertSame('10.0.0.5', $this->callPrivate($this->dashboard, 'connectionHost', ['addr' => 'tcp:10.0.0.5:51234']));
        $this->assertSame('::1', $this->callPrivate($this->dashboard, 'connectionHost', ['addr' => 'tcp6:[::1]:51234']));
        $this->assertSame('unix:/tmp/m.sock', $this->callPrivate($this->dashboard, 'connectionHost', ['addr' => 'unix:/tmp/m.sock']));
    }
}
