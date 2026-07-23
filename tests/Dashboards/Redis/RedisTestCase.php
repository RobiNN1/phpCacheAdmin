<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use Exception;
use Iterator;
use JsonException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\PredisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\RedisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Dashboards\Redis\RedisMetrics;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;
use Throwable;

abstract class RedisTestCase extends TestCase {
    use SentinelTrait;

    private const CLIENT_LIST =
        "id=3 addr=127.0.0.1:50001 laddr=127.0.0.1:6379 name=worker age=10 idle=5 flags=N db=0 cmd=get user=default tot-mem=20512\r\n"
        ."id=4 addr=127.0.0.1:50002 name= age=1 idle=0 flags=O db=1 cmd=monitor user=alice tot-mem=100\n"
        ."id=5 addr=127.0.0.1:50003 name=app=worker age=1 idle=0 flags=N db=0 cmd=ping user=default tot-mem=100\n";

    private RedisDashboard $dashboard;

    private Redis|Predis|RedisCluster|PredisCluster $redis;

    protected string $client;

    protected static bool $is_cluster = false;

    protected static bool $is_sentinel = false;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$is_cluster = !empty(Config::get('redis')[0]['nodes']);
        self::$is_sentinel = !empty(Config::get('redis')[0]['sentinels']);

        if (self::$is_sentinel) {
            self::skipWithoutServer((string) Config::get('redis')[0]['sentinels'][0], 'Sentinel');
        } elseif (self::$is_cluster) {
            self::skipWithoutServer((string) Config::get('redis')[0]['nodes'][0], 'Cluster node');
        }
    }

    /**
     * @throws DashboardException
     */
    protected function setUp(): void {
        $this->dashboard = new RedisDashboard(new Template(), $this->client);

        if (self::$is_cluster) {
            $config = ['nodes' => Config::get('redis')[0]['nodes']];
        } elseif (self::$is_sentinel) {
            $config = [
                'sentinels'      => Config::get('redis')[0]['sentinels'],
                'sentinelmaster' => Config::get('redis')[0]['sentinelmaster'] ?? 'mymaster',
                'database'       => 10,
            ];
        } else {
            $config = [
                'host'     => Config::get('redis')[0]['host'],
                'port'     => Config::get('redis')[0]['port'],
                'database' => 10,
            ];
        }

        $this->redis = $this->dashboard->connect($config);
        $this->dashboard->redis = $this->redis;
    }

    /**
     * @throws Exception
     */
    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        @unlink($this->consoleHistoryFile());

        $this->redis->flushDatabase();
    }

    private function consoleHistoryFile(): string {
        $name = 'redis_history_'.md5(Helpers::getServerTitle(Config::get('redis')[0]).Config::get('hash', 'pca')).'.json';

        return Config::get('tmpdir', dirname(__DIR__, 3).'/tmp').'/console/'.$name;
    }

    /**
     * @throws Exception
     */
    public function testAjaxPanels(): void {
        $_GET['db'] = 10;
        $_GET['panels'] = '';

        $panels = $this->dashboard->ajax();

        $this->assertJson($panels);
        $this->assertStringNotContainsString('"error"', $panels);
    }

    /**
     * @throws Exception
     */
    public function testAjaxViewKey(): void {
        $key = 'pu-test-ajax-view';
        $this->redis->set($key, 'view-data');

        $_GET['db'] = 10;
        $_GET['view'] = 'key';
        $_GET['key'] = $key;

        $rendered = $this->dashboard->ajax();

        $this->assertStringContainsString($key, $rendered);
        $this->assertStringContainsString('view-data', $rendered);
    }

    /**
     * @throws Exception
     */
    public function testAjaxDeleteKeyWithInvalidCsrf(): void {
        $key = 'pu-test-ajax';
        $this->redis->set($key, 'data');

        $_GET['db'] = 10;
        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);
        $this->setCsrfToken(false);

        $this->assertSame(Helpers::alert('Invalid CSRF token.', 'error'), $this->dashboard->ajax());
        $this->assertSame(1, $this->redis->exists($key));
    }

    /**
     * @throws Exception
     */
    public function testAjaxDeleteKey(): void {
        $key = 'pu-test-ajax';
        $this->redis->set($key, 'data');

        $_GET['db'] = 10;
        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);
        $this->setCsrfToken();

        $this->assertSame(
            Helpers::alert(sprintf('Key "%s" has been deleted.', $key), 'success'),
            $this->dashboard->ajax()
        );
        $this->assertSame(0, $this->redis->exists($key));
    }

    private function metricsDb(string $server_name): string {
        $dir = Config::get('metricsdir', dirname(__DIR__, 3).'/tmp/metrics');

        return $dir.'/redis_metrics_'.md5($server_name.Config::get('hash', 'pca')).'.db';
    }

    /**
     * @throws JsonException
     */
    public function testMetrics(): void {
        $server_name = 'pu-metrics-'.uniqid('', true);
        $metrics = new RedisMetrics($this->redis, [['name' => $server_name]], 0);

        $data = json_decode($metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertArrayHasKey('hit_rate', $data[0]);
        $this->assertArrayHasKey('memory', $data[0]);
        $this->assertIsArray($data[0]['commands_stats']);

        $data = json_decode($metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);

        @unlink($this->metricsDb($server_name));
    }

    /**
     * @throws JsonException
     */
    public function testMetricsLegacyRowsWithoutCommandsStats(): void {
        $server_name = 'pu-metrics-'.uniqid('', true);
        $metrics = new RedisMetrics($this->redis, [['name' => $server_name]], 0);
        $metrics->collectAndRespond();

        $db = $this->metricsDb($server_name);

        // Databases from before the commands_stats column existed hold NULL in the rows collected back then.
        (new PDO('sqlite:'.$db))->exec('INSERT INTO metrics (timestamp) VALUES ('.(time() - 100).')');

        $data = json_decode($metrics->collectAndRespond(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $data);
        $this->assertSame([], $data[0]['commands_stats']);

        @unlink($db);
    }

    /**
     * @param array<string, mixed> $post
     *
     * @throws Exception
     */
    private function saveData(array $post): void {
        $_POST = array_merge(['encoder' => 'none', 'expire' => -1, 'old_value' => ''], $post);

        Http::stopRedirect();
        $this->dashboard->saveKey();
    }

    /**
     * @param array<int, string>|string $keys
     */
    private function deleteRedisKeys(array|string $keys): void {
        $this->deleteKeysHelper($keys, function (string $key): bool {
            $delete_key = $this->redis->del($key);

            return is_int($delete_key) && $delete_key > 0;
        });
    }

    /**
     * @throws Exception
     */
    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        $this->redis->set($key, 'data');
        $this->deleteRedisKeys($key);
        $this->assertSame(0, $this->redis->exists($key));
    }

    /**
     * @throws Exception
     */
    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        $this->redis->set($key1, 'data1');
        $this->redis->set($key2, 'data2');
        $this->redis->set($key3, 'data3');

        $this->deleteRedisKeys([$key1, $key2, $key3]);

        $this->assertSame(0, $this->redis->exists($key1));
        $this->assertSame(0, $this->redis->exists($key2));
        $this->assertSame(0, $this->redis->exists($key3));
    }

    /**
     * @throws Exception
     */
    #[DataProvider('keysProvider')]
    public function testSetGetKey(string $type, mixed $original, mixed $expected): void {
        $original = is_array($original) || is_object($original) ? serialize($original) : $original;

        $this->redis->set('pu-test-'.$type, $original);
        $this->assertSame($expected, Helpers::mixedToString($this->redis->get('pu-test-'.$type)));
        $this->redis->del('pu-test-'.$type);
    }

    /**
     * @throws Exception
     */
    public function testGetInfo(): void {
        $this->assertArrayHasKey('redis_version', $this->redis->getInfo('server'));

        $databases = implode("\n", array_keys($this->redis->getInfo('keyspace')));
        $this->assertMatchesRegularExpression('/^(db\d+\n?)*$/', $databases);
    }

    /**
     * @throws Exception
     */
    public function testParseInfoOutput(): void {
        $parsed = $this->redis->parseInfoOutput("# Server\r\nredis_version:1.0.0\r\n\r\n# Keyspace\ndb0:keys=5,expires=0,avg_ttl=0\n");

        $this->assertSame('1.0.0', $parsed['server']['redis_version']);
        $this->assertSame('keys=5,expires=0,avg_ttl=0', $parsed['keyspace']['db0']);
    }

    /**
     * @throws Exception
     */
    public function testCombineValues(): void {
        $this->assertSame('8.0.0', $this->redis->combineValues('redis_version', ['8.0.0', '8.0.0'], null)); // identical values collapse
        $this->assertSame(30, $this->redis->combineValues('used_memory', [10, 20], ['used_memory'])); // listed keys are summed
        $this->assertEqualsWithDelta(1.5, $this->redis->combineValues('mem_fragmentation_ratio', [1.0, 2.0], null), PHP_FLOAT_EPSILON); // averaged
        $this->assertSame(20, $this->redis->combineValues('used_memory_peak', [10, 20], null)); // highest value
        $this->assertSame([10, 20], $this->redis->combineValues('unknown_key', [10, 20], null)); // kept per node
    }

    /**
     * @throws Exception
     */
    public function testAggregatedData(): void {
        $result = $this->redis->aggregatedData([
            'stats'    => ['keyspace_hits' => [1, 2]],
            'keyspace' => ['db0' => ['keys=1', 'keys=2']],
        ], ['keyspace_hits']);

        $this->assertSame(3, $result['stats']['keyspace_hits']);
        $this->assertSame(['keys=1', 'keys=2'], $result['keyspace']['db0']); // keyspace stays per node
    }

    /**
     * @throws Exception
     */
    public function testScanKeys(): void {
        for ($i = 0; $i < 50; $i++) {
            $this->redis->set('pu-scan:'.$i, 'value');
        }

        // SCAN batches can overshoot, the limit must still be honored.
        $this->assertCount(10, $this->redis->scanKeys('pu-scan:*', 10));

        $all = $this->redis->scanKeys('pu-scan:*', 1000);
        $this->assertCount(50, $all);
        $this->assertContains('pu-scan:25', $all);
    }

    /**
     * @throws Exception
     */
    public function testDeleteSubKeyFromHash(): void {
        $this->saveData(['rtype' => 'hash', 'key' => 'pu-del-hash', 'value' => 'v1', 'hash_key' => 'f1']);
        $this->saveData(['rtype' => 'hash', 'key' => 'pu-del-hash', 'value' => 'v2', 'hash_key' => 'f2']);

        $this->dashboard->deleteSubKey('hash', 'pu-del-hash', 'f1');

        $this->assertSame(['f2' => 'v2'], $this->dashboard->getAllKeyValues('hash', 'pu-del-hash'));
    }

    /**
     * @throws Exception
     */
    public function testDeleteSubKeyFromList(): void {
        foreach (['a', 'b', 'c'] as $value) {
            $this->saveData(['rtype' => 'list', 'key' => 'pu-del-list', 'value' => $value, 'index' => '']);
        }

        $this->dashboard->deleteSubKey('list', 'pu-del-list', 1);

        $this->assertSame(['a', 'c'], $this->dashboard->getAllKeyValues('list', 'pu-del-list'));
    }

    /**
     * @throws Exception
     */
    public function testDeleteSubKeyFromSet(): void {
        $this->saveData(['rtype' => 'set', 'key' => 'pu-del-set', 'value' => 'm1']);
        $this->saveData(['rtype' => 'set', 'key' => 'pu-del-set', 'value' => 'm2']);

        $this->dashboard->deleteSubKey('set', 'pu-del-set', 0);

        $this->assertCount(1, $this->dashboard->getAllKeyValues('set', 'pu-del-set'));
    }

    /**
     * @throws Exception
     */
    public function testDeleteSubKeyFromZSet(): void {
        $this->saveData(['rtype' => 'zset', 'key' => 'pu-del-zset', 'value' => 'm1', 'score' => 1]);
        $this->saveData(['rtype' => 'zset', 'key' => 'pu-del-zset', 'value' => 'm2', 'score' => 2]);

        $this->dashboard->deleteSubKey('zset', 'pu-del-zset', 0);

        $this->assertSame(['m2'], $this->dashboard->getAllKeyValues('zset', 'pu-del-zset'));
    }

    /**
     * @throws Exception
     */
    public function testDeleteSubKeyFromStream(): void {
        $this->saveData(['rtype' => 'stream', 'key' => 'pu-del-stream', 'value' => '{"f":"1"}', 'stream_id' => '*']);
        $this->saveData(['rtype' => 'stream', 'key' => 'pu-del-stream', 'value' => '{"f":"2"}', 'stream_id' => '*']);

        $ids = array_keys($this->dashboard->getAllKeyValues('stream', 'pu-del-stream'));
        $this->dashboard->deleteSubKey('stream', 'pu-del-stream', $ids[0]);

        $this->assertCount(1, $this->dashboard->getAllKeyValues('stream', 'pu-del-stream'));
    }

    /**
     * @throws Exception
     */
    private function seedSubSearchHash(): void {
        $this->saveData(['rtype' => 'hash', 'key' => 'pu-test-subsearch-hash', 'hash_key' => 'apple', 'value' => 'redfruit']);
        $this->saveData(['rtype' => 'hash', 'key' => 'pu-test-subsearch-hash', 'hash_key' => 'banana', 'value' => 'yellowfruit']);
        $this->saveData(['rtype' => 'hash', 'key' => 'pu-test-subsearch-hash', 'hash_key' => 'carrot', 'value' => 'orangeveg']);

        $_GET['db'] = 10;
        $_GET['view'] = 'key';
        $_GET['key'] = 'pu-test-subsearch-hash';
    }

    /**
     * @throws Exception
     */
    public function testViewKeySubSearchShowsSearchBox(): void {
        $this->seedSubSearchHash();

        $this->assertStringContainsString('id="subsearch_key"', $this->dashboard->ajax());
    }

    /**
     * @throws Exception
     */
    public function testViewKeySubSearchMatchesSubKey(): void {
        $this->seedSubSearchHash();

        $_GET['subsearch'] = 'banana';
        $rendered = $this->dashboard->ajax();

        $this->assertStringContainsString('yellowfruit', $rendered);
        $this->assertStringNotContainsString('redfruit', $rendered);
        $this->assertStringNotContainsString('orangeveg', $rendered);
    }

    /**
     * @throws Exception
     */
    public function testViewKeySubSearchMatchesValue(): void {
        $this->seedSubSearchHash();

        $_GET['subsearch'] = 'orangeveg';
        $rendered = $this->dashboard->ajax();

        $this->assertStringContainsString('orangeveg', $rendered);
        $this->assertStringNotContainsString('redfruit', $rendered);
        $this->assertStringNotContainsString('yellowfruit', $rendered);
    }

    /**
     * @throws Exception
     */
    public function testViewKeySubSearchWithoutMatches(): void {
        $this->seedSubSearchHash();

        $_GET['subsearch'] = 'zzzznomatch';

        $this->assertStringContainsString('No items match your search.', $this->dashboard->ajax());
    }

    /**
     * @throws Exception
     */
    public function testStringType(): void {
        $key = 'pu-test-type-string';

        $this->saveData(['rtype' => 'string', 'key' => $key, 'value' => 'initial_value']);
        $this->assertSame('initial_value', $this->dashboard->getAllKeyValues('string', $key));

        $this->saveData(['rtype' => 'string', 'key' => $key, 'value' => 'updated_value']);
        $this->assertSame('updated_value', $this->dashboard->getAllKeyValues('string', $key));
    }

    /**
     * @throws Exception
     */
    public function testSetType(): void {
        $key = 'pu-test-type-set';

        $this->saveData(['rtype' => 'set', 'key' => $key, 'value' => 'member1']);
        $this->saveData(['rtype' => 'set', 'key' => $key, 'value' => 'member2']);
        $this->assertEqualsCanonicalizing(['member1', 'member2'], $this->dashboard->getAllKeyValues('set', $key));

        $this->saveData(['rtype' => 'set', 'key' => $key, 'value' => 'member2_updated', 'old_value' => 'member2']);
        $this->assertEqualsCanonicalizing(['member1', 'member2_updated'], $this->dashboard->getAllKeyValues('set', $key));
    }

    /**
     * @throws Exception
     */
    public function testListType(): void {
        $key = 'pu-test-type-list';

        $this->saveData(['rtype' => 'list', 'key' => $key, 'value' => 'lvalue1']);
        $this->saveData(['rtype' => 'list', 'key' => $key, 'value' => 'lvalue2', 'index' => '1']);
        $this->assertSame(['lvalue1', 'lvalue2'], $this->dashboard->getAllKeyValues('list', $key));

        $this->saveData(['rtype' => 'list', 'key' => $key, 'value' => 'lvalue1_updated', 'index' => '0']);
        $this->assertSame(['lvalue1_updated', 'lvalue2'], $this->dashboard->getAllKeyValues('list', $key));
    }

    /**
     * @throws Exception
     */
    public function testZSetType(): void {
        $key = 'pu-test-type-zset';

        $this->saveData(['rtype' => 'zset', 'key' => $key, 'value' => 'zvalue1', 'score' => 10]);
        $this->saveData(['rtype' => 'zset', 'key' => $key, 'value' => 'zvalue2', 'score' => 20]);
        $this->assertEqualsCanonicalizing(['zvalue1', 'zvalue2'], $this->dashboard->getAllKeyValues('zset', $key));
        $this->assertEqualsWithDelta(20.0, $this->redis->zScore($key, 'zvalue2'), PHP_FLOAT_EPSILON);

        $this->saveData(['rtype' => 'zset', 'key' => $key, 'value' => 'zvalue2_updated', 'old_value' => 'zvalue2', 'score' => 30]);
        $this->assertEqualsCanonicalizing(['zvalue1', 'zvalue2_updated'], $this->dashboard->getAllKeyValues('zset', $key));
        $this->assertEqualsWithDelta(30.0, $this->redis->zScore($key, 'zvalue2_updated'), PHP_FLOAT_EPSILON);
        $this->assertEmpty($this->redis->zScore($key, 'zvalue2'));
    }

    /**
     * @throws Exception
     */
    public function testHashType(): void {
        $key = 'pu-test-type-hash';

        $this->saveData(['rtype' => 'hash', 'key' => $key, 'hash_key' => 'field1', 'value' => 'hvalue1']);
        $this->saveData(['rtype' => 'hash', 'key' => $key, 'hash_key' => 'field2', 'value' => 'hvalue2']);
        $this->assertSame(['field1' => 'hvalue1', 'field2' => 'hvalue2'], $this->dashboard->getAllKeyValues('hash', $key));

        $this->saveData(['rtype' => 'hash', 'key' => $key, 'hash_key' => 'field1', 'value' => 'hvalue1_updated']);
        $this->assertSame('hvalue1_updated', $this->dashboard->getAllKeyValues('hash', $key)['field1']);
    }

    /**
     * @throws Exception
     */
    public function testStreamType(): void {
        $key = 'pu-test-type-stream';

        $this->saveData([
            'rtype' => 'stream',
            'key'   => $key,
            'value' => json_encode(['field1' => 'v1', 'field2' => 'v2'], JSON_THROW_ON_ERROR),
        ]);
        $this->saveData([
            'rtype' => 'stream',
            'key'   => $key,
            'value' => json_encode(['field3' => 'v3'], JSON_THROW_ON_ERROR),
        ]);

        $all_values = array_values($this->dashboard->getAllKeyValues('stream', $key));
        $this->assertEquals(['field1' => 'v1', 'field2' => 'v2'], $all_values[0]);
        $this->assertEquals(['field3' => 'v3'], $all_values[1]);
    }

    /**
     * @throws Exception
     */
    public function testStreamTypeEditEntry(): void {
        $key = 'pu-test-type-stream';

        $this->saveData(['rtype' => 'stream', 'key' => $key, 'value' => json_encode(['field1' => 'v1'], JSON_THROW_ON_ERROR)]);
        $this->saveData(['rtype' => 'stream', 'key' => $key, 'value' => json_encode(['field3' => 'v3'], JSON_THROW_ON_ERROR)]);

        $_GET['stream_id'] = array_key_last($this->redis->xRange($key, '-', '+'));
        $this->saveData(['rtype' => 'stream', 'key' => $key, 'value' => json_encode(['field3' => 'edited'], JSON_THROW_ON_ERROR)]);

        $all_values = array_values($this->dashboard->getAllKeyValues('stream', $key));

        $this->assertEquals(['field1' => 'v1'], $all_values[0]); // other entries stay untouched
        $this->assertEquals(['field3' => 'edited'], $all_values[1]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    private function streamWithGroups(string $key): array {
        foreach (range(1, 5) as $i) {
            $this->redis->streamAdd($key, '*', ['order' => (string) $i]);
        }

        $this->redis->streamCreateGroup($key, 'pu-workers');
        $this->redis->streamCreateGroup($key, 'pu-audit');

        $this->redis->streamReadGroup($key, 'pu-workers', 'pu-worker-1', 2);
        $this->redis->streamReadGroup($key, 'pu-workers', 'pu-worker-2', 1);

        return $this->dashboard->streamGroupsInfo($key);
    }

    /**
     * @throws Throwable
     */
    public function testStreamGroups(): void {
        $groups = array_column($this->streamWithGroups('pu-test-stream-groups')['groups'], null, 'name');

        $this->assertCount(2, $groups);
        $this->assertSame(2, $groups['pu-workers']['consumers']);
        $this->assertSame(3, $groups['pu-workers']['pending']);
        $this->assertSame(0, $groups['pu-audit']['consumers']);
        $this->assertSame(0, $groups['pu-audit']['pending']);
    }

    /**
     * @throws Throwable
     */
    public function testStreamGroupsTotalPending(): void {
        $this->assertSame(3, $this->streamWithGroups('pu-test-stream-pending')['total_pending']);
    }

    /**
     * @throws Throwable
     */
    public function testStreamGroupsOldestPendingEntry(): void {
        $key = 'pu-test-stream-oldest';
        $groups = array_column($this->streamWithGroups($key)['groups'], null, 'name');

        $this->assertSame(array_key_first($this->redis->xRange($key, '-', '+')), $groups['pu-workers']['oldest_pending']);
        $this->assertNull($groups['pu-audit']['oldest_pending']); // nothing was delivered, so nothing is pending
    }

    /**
     * @throws Throwable
     */
    public function testStreamGroupConsumers(): void {
        $groups = array_column($this->streamWithGroups('pu-test-stream-consumers')['groups'], null, 'name');
        $consumers = array_column($groups['pu-workers']['consumer_list'], null, 'name');

        $this->assertSame(2, $consumers['pu-worker-1']['pending']);
        $this->assertSame(1, $consumers['pu-worker-2']['pending']);
        $this->assertSame([], $groups['pu-audit']['consumer_list']);
    }

    /**
     * @throws Exception
     */
    public function testStreamWithoutGroups(): void {
        $key = 'pu-test-stream-nogroups';

        $this->redis->streamAdd($key, '*', ['field' => 'value']);

        $this->assertSame([], $this->dashboard->streamGroupsInfo($key));
    }

    /**
     * @throws Exception
     */
    public function testStreamGroupsOfAMissingKey(): void {
        $this->assertSame([], $this->dashboard->streamGroupsInfo('pu-test-stream-missing'));
    }

    /**
     * @throws Exception
     */
    private function vectorSet(string $key): void {
        if (!$this->redis->checkModule('vectorset')) {
            $this->markTestSkipped('The vectorset module is not loaded.');
        }

        $this->redis->del($key);

        $this->redis->vectorAdd($key, 'cat', [1.0, 0.2, 0.1]);
        $this->redis->vectorAdd($key, 'dog', [0.9, 0.3, 0.1]);
        $this->redis->vectorAdd($key, 'car', [0.1, 0.1, 1.0]);
    }

    /**
     * @throws Exception
     */
    public function testVectorSetType(): void {
        $key = 'pu-test-type-vectorset';
        $this->vectorSet($key);

        $this->assertSame('vectorset', $this->redis->getKeyType($key));
        $this->assertEqualsCanonicalizing(['cat', 'dog', 'car'], array_keys($this->dashboard->getAllKeyValues('vectorset', $key)));
    }

    /**
     * @throws Exception
     */
    public function testVectorSetEmbedding(): void {
        $key = 'pu-test-vectorset-embedding';
        $this->vectorSet($key);

        $vector = $this->redis->vectorEmbedding($key, 'car');

        // int8 quantization is lossy, so the values only come back close to what was stored.
        $this->assertCount(3, $vector);
        $this->assertEqualsWithDelta([0.1, 0.1, 1.0], $vector, 0.02);
    }

    /**
     * @throws Exception
     */
    public function testVectorSetInfo(): void {
        $key = 'pu-test-vectorset-info';
        $this->vectorSet($key);

        $info = $this->dashboard->vectorSetInfo($key);

        $this->assertSame(3, $info['dimension']);
        $this->assertSame(3, $info['size']);
        $this->assertSame(0, $info['truncated']);
        $this->assertCount(4, $this->dashboard->vectorSetTiles($info));
    }

    /**
     * @throws Exception
     */
    public function testVectorSetSaveAddsAnElement(): void {
        $key = 'pu-test-vectorset-save';
        $this->vectorSet($key);

        $this->saveData(['rtype' => 'vectorset', 'key' => $key, 'value' => '[0.5, 0.5, 0.5]', 'element' => 'bike']);

        $this->assertEqualsCanonicalizing(['cat', 'dog', 'car', 'bike'], $this->redis->vectorMembers($key, 100));
        $this->assertEqualsWithDelta([0.5, 0.5, 0.5], $this->redis->vectorEmbedding($key, 'bike'), 0.02);
    }

    /**
     * @throws Exception
     */
    public function testVectorSetSaveAcceptsPlainNumbers(): void {
        $key = 'pu-test-vectorset-plain';
        $this->vectorSet($key);

        $this->saveData(['rtype' => 'vectorset', 'key' => $key, 'value' => '0.5 0.5 0.5', 'element' => 'bike']);

        $this->assertContains('bike', $this->redis->vectorMembers($key, 100));
    }

    /**
     * @throws Exception
     */
    public function testVectorSetDeleteSubKey(): void {
        $key = 'pu-test-vectorset-delete';
        $this->vectorSet($key);

        $this->dashboard->deleteSubKey('vectorset', $key, 'dog');

        $this->assertEqualsCanonicalizing(['cat', 'car'], $this->redis->vectorMembers($key, 100));
    }

    /**
     * @throws Exception
     */
    public function testVectorSetItemCountInTheKeyList(): void {
        $key = 'pu-test-vectorset-count';
        $this->vectorSet($key);

        $info = $this->redis->pipelineKeys([$key]);

        $this->assertSame('vectorset', $info[$key]['type']);
        $this->assertSame(3, $info[$key]['count']);
    }

    /**
     * @throws Exception
     */
    public function testVectorSetInfoOfAMissingKey(): void {
        if (!$this->redis->checkModule('vectorset')) {
            $this->markTestSkipped('The vectorset module is not loaded.');
        }

        $this->assertSame([], $this->dashboard->vectorSetInfo('pu-test-vectorset-missing'));
    }

    /**
     * @throws Exception
     */
    public function testJSONType(): void {
        if (!$this->redis->checkModule('ReJSON')) {
            $this->markTestSkipped('The ReJSON module is not loaded.');
        }

        $key = 'pu-test-type-json';
        $json = '{"name":"phpCacheAdmin","numbers":[1,2,3],"nested":{"enabled":true}}';

        $this->saveData(['rtype' => 'json', 'key' => $key, 'value' => $json]);

        $stored = $this->dashboard->getAllKeyValues('json', $key);
        $this->assertJson($stored);
        $this->assertEquals(
            json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            json_decode($stored, true, 512, JSON_THROW_ON_ERROR)
        );

        $updated = '{"name":"updated","numbers":[4,5]}';
        $this->saveData(['rtype' => 'json', 'key' => $key, 'value' => $updated]);
        $this->assertEquals(
            json_decode($updated, true, 512, JSON_THROW_ON_ERROR),
            json_decode($this->dashboard->getAllKeyValues('json', $key), true, 512, JSON_THROW_ON_ERROR)
        );

        $this->redis->del($key);
    }

    /**
     * @throws Exception
     */
    public function testGetAllKeysTableView(): void {
        $this->redis->set('pu-test-table1', 'value1');
        $this->redis->set('pu-test-table2', 'value2');
        $_GET['s'] = 'pu-test-table*';

        $result = $this->dashboard->getAllKeys();

        $info = [
            'bytes_size' => 0,
            'type'       => 'string',
            'ttl'        => 'Doesn\'t expire',
        ];

        $expected = [
            [
                'key'   => 'pu-test-table1',
                'items' => null,
                'info'  => array_merge(['link_title' => 'pu-test-table1'], $info),
            ],
            [
                'key'   => 'pu-test-table2',
                'items' => null,
                'info'  => array_merge(['link_title' => 'pu-test-table2'], $info),
            ],
        ];

        $result = $this->dashboard->keysTableView($result);
        $result = $this->normalizeInfoFields($result, ['bytes_size']);

        $this->assertEquals($this->sortKeys($expected), $this->sortKeys($result));
    }

    /**
     * @throws Exception
     */
    public function testGetAllKeysTreeView(): void {
        $this->redis->set('pu-test-tree1:sub1', 'value1');
        $this->redis->set('pu-test-tree1:sub2', 'value2');
        $this->redis->set('pu-test-tree2', 'value3');
        $_GET['s'] = 'pu-test-tree*';

        $result = $this->dashboard->getAllKeys();

        $info = [
            'bytes_size' => 0,
            'type'       => 'string',
            'ttl'        => 'Doesn\'t expire',
        ];

        $expected = [
            'pu-test-tree1' => [
                'type'     => 'folder',
                'name'     => 'pu-test-tree1',
                'path'     => 'pu-test-tree1',
                'children' => [
                    ['type' => 'key', 'name' => 'sub1', 'key' => 'pu-test-tree1:sub1', 'items' => null, 'info' => $info,],
                    ['type' => 'key', 'name' => 'sub2', 'key' => 'pu-test-tree1:sub2', 'items' => null, 'info' => $info,],
                ],
                'expanded' => false,
                'count'    => 2,
            ],
            ['type' => 'key', 'name' => 'pu-test-tree2', 'key' => 'pu-test-tree2', 'items' => null, 'info' => $info,],
        ];

        $result = $this->dashboard->keysTreeView($result);
        $result = $this->normalizeInfoFields($result, ['bytes_size']);

        $this->assertEquals($this->sortTreeKeys($expected), $this->sortTreeKeys($result));
    }

    /**
     * @throws Exception
     */
    public function testPipelineKeys(): void {
        $this->dashboard->store('string', 'pu-test-string', 'some-value');
        $this->dashboard->store('string', 'pu-test-ttl', 'expires soon', '', ['ttl' => 60]);

        foreach (['a', 'b', 'c'] as $value) {
            $this->dashboard->store('list', 'pu-test-list', $value);
        }

        foreach (['m1', 'm2', 'm3', 'm4'] as $member) {
            $this->dashboard->store('set', 'pu-test-set', $member);
        }

        foreach (['field1' => 'val1', 'field2' => 'val2'] as $field => $value) {
            $this->dashboard->store('hash', 'pu-test-hash', $value, '', ['hash_key' => $field]);
        }

        $keys_to_test = ['pu-test-string', 'pu-test-list', 'pu-test-hash', 'pu-test-set', 'pu-test-ttl'];
        $results = $this->redis->pipelineKeys($keys_to_test);

        $expected_data = [
            'pu-test-string' => ['type' => 'string', 'count' => null, 'ttl_check' => -1],
            'pu-test-list'   => ['type' => 'list', 'count' => 3, 'ttl_check' => -1],
            'pu-test-hash'   => ['type' => 'hash', 'count' => 2, 'ttl_check' => -1],
            'pu-test-set'    => ['type' => 'set', 'count' => 4, 'ttl_check' => -1],
            'pu-test-ttl'    => ['type' => 'string', 'count' => null, 'ttl_check' => 'positive'],
        ];

        $this->assertCount(count($expected_data), $results);

        foreach ($expected_data as $key => $expected) {
            $this->assertArrayHasKey($key, $results);
            $actual = $results[$key];

            $this->assertSame($expected['type'], $actual['type']);
            $this->assertSame($expected['count'], $actual['count']);
            $this->assertGreaterThan(0, $actual['size']);

            if ($expected['ttl_check'] === 'positive') {
                $this->assertGreaterThan(0, $actual['ttl']);
            } else {
                $this->assertSame($expected['ttl_check'], $actual['ttl']);
            }
        }
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
     * @throws Exception
     */
    private function runAnalysis(): array {
        for ($i = 0; $i < 200; $i++) {
            $this->redis->set('pu-analysis:cache:page:'.$i, str_repeat('x', 50));
        }

        $this->redis->set('pu-analysis:zz-blob:huge', str_repeat('x', 50000));
        $this->redis->set('pu-analysis:ttl:soon', 'value');
        $this->redis->expire('pu-analysis:ttl:soon', 300);
        $this->redis->set('pu-analysis-no-namespace', 'value');

        foreach (range(1, 30) as $i) {
            $this->dashboard->store('list', 'pu-analysis:list:big', 'item'.$i);
        }

        $keys = $this->redis->scanKeys('pu-analysis*', 10000);

        sort($keys);

        return $this->dashboard->analyzeKeys($keys, $this->redis->pipelineKeys($keys), 2, count($keys));
    }

    /**
     * @throws Exception
     */
    public function testAnalysisSummary(): void {
        $analysis = $this->runAnalysis();

        $this->assertSame(204, $analysis['summary']['scanned']);
        $this->assertSame(5, $analysis['summary']['namespaces']);
        $this->assertSame(203, $analysis['summary']['no_expiry']['count']);
        $this->assertCount(4, $analysis['tiles']);
        $this->assertTrue($analysis['memory']);
    }

    /**
     * @throws Exception
     */
    public function testAnalysisTopKeys(): void {
        $analysis = $this->runAnalysis();

        $this->assertSame('pu-analysis:zz-blob:huge', $analysis['top_memory'][0]['key']);
        $this->assertGreaterThan(50000, $analysis['top_memory'][0]['size']);

        $this->assertSame('pu-analysis:list:big', $analysis['top_length'][0]['key']);
        $this->assertSame(30, $analysis['top_length'][0]['items']);
    }

    /**
     * @throws Exception
     */
    public function testAnalysisNamespaces(): void {
        $analysis = $this->runAnalysis();

        $cache = $this->findRow($analysis['namespaces'], 'pu-analysis:cache');
        $this->assertSame(200, $cache['count']);
        $this->assertGreaterThan(0, $cache['memory']);

        $this->assertSame(1, $this->findRow($analysis['namespaces'], '(no namespace)')['count']);
    }

    /**
     * @throws Exception
     */
    public function testAnalysisTypeAndExpiryBreakdown(): void {
        $analysis = $this->runAnalysis();

        $this->assertSame(203, $this->findRow($analysis['types'], 'string')['count']);
        $this->assertSame(1, $this->findRow($analysis['types'], 'list')['count']);

        $this->assertSame(203, $this->findRow($analysis['expiry'], 'No expiry')['count']);
        $this->assertSame(1, $this->findRow($analysis['expiry'], '< 1 hour')['count']);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    private function recommendationsFor(): array {
        $keys = $this->redis->scanKeys('pu-rec*', 1000);
        $pipeline = $this->redis->pipelineKeys($keys);

        $context = ['maxmemory' => 0, 'maxmemory_policy' => 'noeviction', 'hash_directive' => 'hash-max-listpack-entries', 'hash_limit' => 128];

        return $this->dashboard->analyzeKeys($keys, $pipeline, 2, count($keys), $context)['recommendations'];
    }

    /**
     * @throws Exception
     */
    public function testAnalysisRecommendsBigKeys(): void {
        $this->redis->set('pu-rec:blob:big', str_repeat('x', 1_200_000));
        $this->redis->set('pu-rec:small:1', 'value');

        $big = $this->findRow($this->recommendationsFor(), 'Big keys');

        $this->assertSame('warning', $big['status']); // over 1 MB but under the 10 MB mark
        $this->assertCount(1, $big['keys']);
        $this->assertSame('pu-rec:blob:big', $big['keys'][0]['key']);
    }

    /**
     * @throws Exception|Throwable
     */
    public function testAnalysisRecommendsLongCollections(): void {
        // Seeded with one command, 5200 individual round trips only add wall time.
        $items = array_map(static fn (int $i): string => 'item'.$i, range(1, 5200));
        $this->redis->consoleCommand(['RPUSH', 'pu-rec:list:long', ...$items]);

        $long = $this->findRow($this->recommendationsFor(), 'Long collections');

        $this->assertSame('pu-rec:list:long', $long['keys'][0]['key']);
    }

    /**
     * @throws Exception
     */
    public function testAnalysisRecommendsHashesPastListpackLimit(): void {
        foreach (range(1, 200) as $i) {
            $this->dashboard->store('hash', 'pu-rec:hash:wide', 'v'.$i, '', ['hash_key' => 'f'.$i]);
        }

        $hashes = $this->findRow($this->recommendationsFor(), 'Hashes past the listpack limit');

        $this->assertSame('info', $hashes['status']);
        $this->assertSame('pu-rec:hash:wide', $hashes['keys'][0]['key']);
        $this->assertStringContainsString('128', (string) $hashes['directive']);
    }

    /**
     * @throws Exception
     */
    public function testAnalysisWithoutMemoryUsage(): void {
        $this->redis->set('pu-nomem:a:1', str_repeat('x', 1_200_000));
        $this->redis->set('pu-nomem:b:1', 'value');

        $keys = $this->redis->scanKeys('pu-nomem*', 100);
        $pipeline = $this->redis->pipelineKeys($keys);

        $analysis = $this->dashboard->analyzeKeys($keys, $pipeline, 2, 2, ['memory' => false]);

        $this->assertFalse($analysis['memory']);
        $this->assertSame([], $analysis['top_memory']);
        $this->assertCount(3, $analysis['tiles']); // the memory tile is gone
        $this->assertSame('Keys scanned', $analysis['tiles'][0]['label']);
        $this->assertSame('Namespaces', $analysis['tiles'][1]['label']);

        $this->assertSame([50.0, 50.0], array_column($analysis['namespaces'], 'percent'));

        $this->assertSame([], array_filter($analysis['recommendations'], static fn (array $r): bool => $r['name'] === 'Big keys'));
    }

    /**
     * @throws Exception
     */
    public function testAnalysisMemoryIsOffWhenSizesAreMissing(): void {
        $this->redis->set('pu-nomem:a:1', 'value');
        $this->redis->set('pu-nomem:b:1', 'value');

        $keys = $this->redis->scanKeys('pu-nomem*', 100);
        $pipeline = $this->redis->pipelineKeys($keys);

        // A server without MEMORY USAGE reports every size as 0, the memory column would be all zeros.
        foreach (array_keys($pipeline) as $key) {
            $pipeline[$key]['size'] = 0;
        }

        $this->assertFalse($this->dashboard->analyzeKeys($keys, $pipeline, 2, 2, ['memory' => true])['memory']);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws Exception
     */
    #[DataProvider('noExpiryPolicyProvider')]
    public function testAnalysisNoExpiryRecommendationDependsOnThePolicy(array $context, ?string $expected): void {
        $this->redis->set('pu-policy:key', 'value');

        $keys = $this->redis->scanKeys('pu-policy*', 100);
        $found = $this->dashboard->analyzeKeys($keys, $this->redis->pipelineKeys($keys), 1, 1, $context)['recommendations'];

        $status = null;

        foreach ($found as $recommendation) {
            if ($recommendation['name'] === 'Keys without a TTL') {
                $status = $recommendation['status'];
            }
        }

        $this->assertSame($expected, $status);
    }

    /**
     * @return Iterator<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function noExpiryPolicyProvider(): Iterator {
        yield 'volatile policy with a memory limit' => [['maxmemory' => 1_000_000, 'maxmemory_policy' => 'volatile-lru'], 'critical'];
        yield 'noeviction with a memory limit' => [['maxmemory' => 1_000_000, 'maxmemory_policy' => 'noeviction'], 'warning'];
        yield 'noeviction without a memory limit' => [['maxmemory' => 0, 'maxmemory_policy' => 'noeviction'], 'warning'];
        yield 'allkeys eviction policy' => [['maxmemory' => 1_000_000, 'maxmemory_policy' => 'allkeys-lru'], null];
        yield 'no server context' => [[], null];
    }

    /**
     * @throws Exception
     */
    public function testAnalysisNamespaceDepth(): void {
        $this->redis->set('pu-depth:a:b:c', 'value');

        $keys = $this->redis->scanKeys('pu-depth*', 100);
        $pipeline = $this->redis->pipelineKeys($keys);

        $names = static fn (array $analysis): array => array_column($analysis['namespaces'], 'name');

        $this->assertSame(['pu-depth'], $names($this->dashboard->analyzeKeys($keys, $pipeline, 1, 1)));
        $this->assertSame(['pu-depth:a'], $names($this->dashboard->analyzeKeys($keys, $pipeline, 2, 1)));
        $this->assertSame(['pu-depth:a:b'], $names($this->dashboard->analyzeKeys($keys, $pipeline, 5, 1)));
    }

    /**
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('monitorLineProvider')]
    public function testProfilerParsesMonitorLines(string $line, ?array $expected): void {
        $parsed = $this->dashboard->parseMonitorLine($line);

        if ($expected === null) {
            $this->assertNull($parsed);

            return;
        }

        $this->assertNotNull($parsed);

        foreach ($expected as $field => $value) {
            $this->assertSame($value, $parsed[$field], $field.' does not match');
        }
    }

    /**
     * @return Iterator<string, array{0: string, 1: array<string, mixed>|null}>
     */
    public static function monitorLineProvider(): Iterator {
        yield 'plain command' => [
            '+1700000000.123456 [0 127.0.0.1:6379] "GET" "mykey"',
            ['db' => 0, 'addr' => '127.0.0.1:6379', 'command' => 'GET', 'args' => ['mykey']],
        ];
        // A quoted argument keeps its spaces, splitting on whitespace would tear the value apart.
        yield 'value with spaces' => [
            '+1700000000.123456 [3 127.0.0.1:6379] "set" "k" "value with spaces"',
            ['db' => 3, 'command' => 'SET', 'args' => ['k', 'value with spaces']],
        ];
        yield 'escaped quotes' => [
            '+1700000000.123456 [0 127.0.0.1:6379] "set" "k" "say \"hi\""',
            ['command' => 'SET', 'args' => ['k', 'say "hi"']],
        ];
        yield 'escaped binary' => [
            '+1700000000.123456 [0 127.0.0.1:6379] "set" "k" "\x01\x02"',
            ['command' => 'SET', 'args' => ['k', "\x01\x02"]],
        ];
        // Redis reports commands a script issued with "lua" where the address would be.
        yield 'issued from a script' => [
            '+1700000000.123456 [15 lua] "set" "k" "from-lua"',
            ['db' => 15, 'addr' => 'lua', 'command' => 'SET'],
        ];
        yield 'unix socket client' => [
            '+1700000000.123456 [0 /tmp/redis.sock:0] "PING"',
            ['addr' => '/tmp/redis.sock:0', 'command' => 'PING', 'args' => []],
        ];
        yield 'no plus prefix' => [
            '1700000000.123456 [0 127.0.0.1:6379] "PING"',
            ['command' => 'PING'],
        ];
        // MONITOR sends values whole, a multi-megabyte SET would blow up the response without this.
        yield 'oversized value cut for display' => [
            '+1700000000.123456 [0 127.0.0.1:6379] "set" "k" "'.str_repeat('v', 600).'"',
            ['command' => 'SET', 'args' => ['k', str_repeat('v', 512).'…']],
        ];
        yield 'not a monitor line' => ['+OK', null];
        yield 'empty' => ['', null];
        yield 'no arguments' => ['+1700000000.123456 [0 127.0.0.1:6379] ', null];
    }

    private function backgroundNoise(string $php): void {
        $server = Config::get('redis')[0];

        if (self::$is_cluster) {
            $client = sprintf('$r = new Predis\Client(%s, ["cluster" => "redis"]);', var_export(array_values((array) $server['nodes']), true));
        } else {
            [$host, $port] = self::$is_sentinel ? explode(':', $this->dashboard->sentinel_master) : [(string) $server['host'], (int) ($server['port'] ?? 6379)];

            $client = sprintf('$r = new Predis\Client(["host" => %s, "port" => %d]); $r->select(10);', var_export($host, true), (int) $port);
        }

        $bootstrap = sprintf('require %s; usleep(300000);', var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true));

        exec(sprintf('%s -r %s > /dev/null 2>&1 &', escapeshellarg(PHP_BINARY), escapeshellarg($bootstrap.' '.$client.' '.$php)));
    }

    /**
     * @throws Exception
     */
    public function testProfilerCapture(): void {
        $this->backgroundNoise('$r->set("pu-profiler-key", "a value with spaces"); $r->get("pu-profiler-key");');

        // The noise lands ~0.4s in, the rest of the window only adds wall time to the suite.
        $commands = $this->dashboard->captureCommands(2, 100);

        $this->assertNotEmpty($commands);

        $set = array_values(array_filter($commands, static fn (array $c): bool => $c['command'] === 'SET' && ($c['args'][0] ?? '') === 'pu-profiler-key'));

        $this->assertCount(1, $set);
        $this->assertSame(['pu-profiler-key', 'a value with spaces'], $set[0]['args']);
        $this->assertEqualsWithDelta(time(), $set[0]['time'], 10);

        // Sorted by the time the server ran them, so a GET that followed a SET cannot come first.
        $times = array_column($commands, 'time');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
    }

    /**
     * @throws Exception
     */
    public function testProfilerCaptureHonorsTheLimit(): void {
        $this->backgroundNoise(self::$is_cluster
            ? 'for ($i = 0; $i < 500; $i++) { $r->set("pu-profiler-flood-".$i, "v"); }'
            : '$p = $r->pipeline(); for ($i = 0; $i < 500; $i++) { $p->set("pu-profiler-flood-".$i, "v"); } $p->execute();');

        $this->assertCount(5, $this->dashboard->captureCommands(3, 5));
    }

    /**
     * @throws Exception
     */
    public function testSlowlog(): void {
        $config_key = 'slowlog-log-slower-than';
        $original_config_value = $this->redis->execConfig('GET', $config_key)[$config_key];

        $this->redis->resetSlowlog();
        $this->redis->execConfig('SET', $config_key, '0');
        $this->redis->set('pu-test-slowlog-key', 'some-slow-value');

        $slowlog_entries = $this->redis->getSlowlog(10);
        $this->assertIsInt($slowlog_entries[1][0]);
        $this->assertIsInt($slowlog_entries[1][1]);
        $this->assertIsInt($slowlog_entries[1][2]);
        $this->assertIsArray($slowlog_entries[1][3]);
        $this->redis->execConfig('SET', $config_key, $original_config_value);
        $this->assertTrue($this->redis->resetSlowlog());
    }

    public function testParseClientListFields(): void {
        $clients = $this->redis->parseClientList(self::CLIENT_LIST, '4', '127.0.0.1:7000');

        $this->assertCount(3, $clients);

        $this->assertSame('3', $clients[0]['id']);
        $this->assertSame('127.0.0.1:50001', $clients[0]['addr']);
        $this->assertSame('worker', $clients[0]['name']);
        $this->assertSame('20512', $clients[0]['tot-mem']);

        // An empty value still has to be a key.
        $this->assertSame('', $clients[1]['name']);
        $this->assertSame('alice', $clients[1]['user']);
    }

    public function testParseClientListNameWithEquals(): void {
        $this->assertSame('app=worker', $this->redis->parseClientList(self::CLIENT_LIST)[2]['name']);
    }

    public function testParseClientListMarksSelfAndStampsNode(): void {
        $clients = $this->redis->parseClientList(self::CLIENT_LIST, '4', '127.0.0.1:7000');

        $this->assertFalse($clients[0]['self']);
        $this->assertTrue($clients[1]['self']);

        // The node is stamped on every row.
        $this->assertSame('127.0.0.1:7000', $clients[0]['node']);
        $this->assertSame('127.0.0.1:7000', $clients[1]['node']);
    }

    public function testParseClientListWithInvalidInput(): void {
        $this->assertSame([], $this->redis->parseClientList(''));
        $this->assertSame([], $this->redis->parseClientList('garbage without an id'));
    }

    /**
     * @throws Exception
     */
    public function testGetClients(): void {
        $clients = $this->redis->getClients();

        $this->assertNotEmpty($clients);

        $self = array_values(array_filter($clients, static fn (array $client): bool => $client['self'] === true));

        $this->assertNotEmpty($self);

        if (!self::$is_cluster) {
            $this->assertCount(1, $self);
        }

        $this->assertNotSame('', $self[0]['addr']);
        $this->assertArrayHasKey('age', $self[0]);
    }

    /**
     * @throws Exception
     */
    public function testKillClient(): void {
        [$host, $port] = $this->pubSubAddress();
        $victim = stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, 3);
        $this->assertNotFalse($victim, 'Could not open a second connection: '.$errstr);

        fwrite($victim, $this->respCommand('CLIENT', 'SETNAME', 'pu-kill-me'));
        fgets($victim);

        $target = null;

        foreach ($this->redis->getClients() as $client) {
            if (($client['name'] ?? '') === 'pu-kill-me') {
                $target = (string) $client['id'];
            }
        }

        $this->assertNotNull($target, 'The second connection is missing from CLIENT LIST.');
        $this->assertTrue($this->redis->killClient($target));
        $this->assertNotContains('pu-kill-me', array_column($this->redis->getClients(), 'name'));

        // Killing it again reports that there was nothing to kill, rather than claiming success.
        $this->assertFalse($this->redis->killClient($target));

        fclose($victim);
    }

    public function testFormatClient(): void {
        $client = $this->dashboard->formatClient([
            'id'      => '77',
            'addr'    => '127.0.0.1:50001',
            'name'    => 'worker',
            'user'    => 'default',
            'db'      => '2',
            'age'     => '120',
            'idle'    => '5',
            'tot-mem' => '20512',
            'flags'   => 'Sx',
            'cmd'     => 'get',
            'node'    => '127.0.0.1:7000',
            'self'    => true,
        ]);

        $this->assertSame('77', $client['id']);
        $this->assertSame('127.0.0.1:50001', $client['addr']);
        $this->assertSame(120, $client['age']);
        $this->assertSame(5, $client['idle']);
        $this->assertSame(20512, $client['memory']);
        $this->assertSame(['Replica', 'In MULTI'], $client['flags']);
        $this->assertSame('get', $client['command']);
        $this->assertSame('127.0.0.1:7000', $client['node']);
        $this->assertTrue($client['self']);
    }

    public function testFormatClientDefaults(): void {
        $client = $this->dashboard->formatClient(['id' => '1', 'flags' => 'N']);

        $this->assertSame('', $client['addr']);
        $this->assertSame(0, $client['age']);
        $this->assertSame(0, $client['memory']);
        $this->assertSame([], $client['flags']);
        $this->assertFalse($client['self']);
    }

    /**
     * @throws Exception
     */
    public function testClientsTab(): void {
        $_GET['tab'] = 'clients';

        $html = $this->dashboard->dashboard();

        $this->assertStringContainsString('Connected clients', $html);
        $this->assertStringContainsString('This dashboard', $html); // the dashboard's own connection is marked
    }

    /**
     * @throws Exception
     */
    public function testClientsTabKillWithInvalidCsrf(): void {
        $_GET['tab'] = 'clients';
        $_POST['kill_client'] = '1';
        $this->setCsrfToken(false);

        $this->expectOutputRegex('/Invalid CSRF token/');
        $this->dashboard->dashboard();
    }

    /**
     * @throws Exception
     */
    public function testClientsTabKillUnknownClient(): void {
        $_GET['tab'] = 'clients';
        $_POST['kill_client'] = '999999999';
        $this->setCsrfToken();

        $this->expectOutputRegex('/no longer connected/');
        $this->dashboard->dashboard();
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function pubSubAddress(): array {
        $server = Config::get('redis')[0];

        if (self::$is_cluster) {
            [$host, $port] = explode(':', (string) $server['nodes'][0]) + [1 => '6379'];

            return [$host, (int) $port];
        }

        if (self::$is_sentinel) {
            [$host, $port] = explode(':', $this->dashboard->sentinel_master);

            return [$host, (int) $port];
        }

        return [(string) $server['host'], (int) ($server['port'] ?? 6379)];
    }

    private function respCommand(string ...$args): string {
        $command = '*'.count($args)."\r\n";

        foreach ($args as $arg) {
            $command .= '$'.strlen($arg)."\r\n".$arg."\r\n";
        }

        return $command;
    }

    /**
     * @return resource
     */
    private function openSubscriber(string $channel) {
        [$host, $port] = $this->pubSubAddress();
        $subscriber = stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, 3);
        $this->assertNotFalse($subscriber, 'Could not open a raw subscriber socket: '.$errstr);
        stream_set_timeout($subscriber, 3);

        fwrite($subscriber, $this->respCommand('SUBSCRIBE', $channel));

        for ($i = 0; $i < 6; $i++) { // subscribe confirmation (*3, $9, subscribe, $len, channel, :1)
            fgets($subscriber);
        }

        return $subscriber;
    }

    public function testParseNumSubReply(): void {
        $this->assertSame(['a' => 2, 'b' => 0], $this->redis->parseNumSubReply(['a', 2, 'b', 0]));
        $this->assertSame(['a' => 2], $this->redis->parseNumSubReply(['a' => '2'])); // phpredis can return an associative reply
        $this->assertSame([], $this->redis->parseNumSubReply([]));
    }

    /**
     * @throws Exception
     */
    public function testPubSubStats(): void {
        $channel = 'pu-pubsub-stats';
        $subscriber = $this->openSubscriber($channel);

        $stats = $this->redis->pubSubStats('pu-pubsub-*');

        $this->assertSame(1, $stats['channels'][$channel] ?? null);
        $this->assertIsInt($stats['patterns']);

        fclose($subscriber);
    }

    /**
     * @throws Exception
     */
    public function testPublishReachesSubscriber(): void {
        $channel = 'pu-pubsub-publish';

        $baseline = $this->redis->publishMessage($channel, 'baseline');
        $this->assertGreaterThanOrEqual(0, $baseline);

        $subscriber = $this->openSubscriber($channel);

        $receivers = $this->redis->publishMessage($channel, 'hello-subscriber');

        if (!self::$is_cluster) {
            $this->assertSame($baseline + 1, $receivers);
        } else {
            // In a cluster, the reply only counts receivers connected to the node that ran PUBLISH.
            $this->assertGreaterThanOrEqual(0, $receivers);
        }

        $received = '';

        while (($line = fgets($subscriber)) !== false) {
            $received .= $line;

            if (str_contains($received, 'hello-subscriber')) {
                break;
            }
        }

        $this->assertStringContainsString('hello-subscriber', $received);
        fclose($subscriber);
    }

    /**
     * @throws Exception
     */
    public function testCaptureMessages(): void {
        [$host, $port] = $this->pubSubAddress();

        // Publish two messages from a background process while captureMessages() is blocking.
        $payload = $this->respCommand('PUBLISH', 'pu-pubsub-cap-news', 'first message')
            .$this->respCommand('PUBLISH', 'pu-pubsub-cap-news', 'second message');

        $publisher = sprintf(
            'usleep(400000); $s = stream_socket_client(%s); fwrite($s, base64_decode(%s)); fclose($s);',
            var_export('tcp://'.$host.':'.$port, true),
            var_export(base64_encode($payload), true)
        );

        exec(sprintf('%s -r %s > /dev/null 2>&1 &', escapeshellarg(PHP_BINARY), escapeshellarg($publisher)));

        $messages = $this->redis->captureMessages('pu-pubsub-cap-*', 3, 2);

        $this->assertCount(2, $messages);
        $this->assertSame('pu-pubsub-cap-news', $messages[0]['channel']);
        $this->assertSame('first message', $messages[0]['message']);
        $this->assertSame('second message', $messages[1]['message']);
        $this->assertEqualsWithDelta(time(), $messages[0]['time'], 10);

        // The connection must remain usable after the blocking capture.
        $this->redis->set('pu-pubsub-after', 'ok');
        $this->assertSame('ok', Helpers::mixedToString($this->redis->get('pu-pubsub-after')));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function ajaxJson(): array {
        return json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws Exception
     */
    public function testPubSubAjaxStats(): void {
        $_GET['db'] = 10;
        $_GET['pubsub'] = '';

        $stats = $this->ajaxJson();

        $this->assertArrayHasKey('channels', $stats);
        $this->assertArrayHasKey('patterns', $stats);
    }

    /**
     * @throws Exception
     */
    public function testPubSubAjaxPublishWithInvalidCsrf(): void {
        $_GET['db'] = 10;
        $_GET['pubsub'] = '';
        $_POST['publish'] = '1';
        $_POST['channel'] = 'pu-pubsub-ajax';
        $_POST['message'] = 'hi';
        $this->setCsrfToken(false);

        $this->assertSame('Invalid CSRF token.', $this->ajaxJson()['error']);
    }

    /**
     * @throws Exception
     */
    public function testPubSubAjaxPublish(): void {
        $_GET['db'] = 10;
        $_GET['pubsub'] = '';
        $_POST['publish'] = '1';
        $_POST['channel'] = 'pu-pubsub-ajax';
        $_POST['message'] = 'hi';
        $this->setCsrfToken();

        $this->assertIsInt($this->ajaxJson()['receivers'] ?? null);
    }

    /**
     * @throws Exception
     */
    public function testPubSubAjaxPublishRequiresChannel(): void {
        $_GET['db'] = 10;
        $_GET['pubsub'] = '';
        $_POST['publish'] = '1';
        $_POST['channel'] = '';
        $_POST['message'] = 'hi';
        $this->setCsrfToken();

        $this->assertSame('Channel name is required.', $this->ajaxJson()['error']);
    }

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function testConsoleCommand(): void {
        $this->assertSame('OK', Helpers::mixedToString($this->redis->consoleCommand(['SET', 'pu-console-key', 'hello'])));
        $this->assertSame('hello', Helpers::mixedToString($this->redis->consoleCommand(['GET', 'pu-console-key'])));
        $this->assertSame('string', Helpers::mixedToString($this->redis->consoleCommand(['TYPE', 'pu-console-key'])));
        $this->assertIsInt($this->redis->consoleCommand(['APPEND', 'pu-console-key', '!']));

        $this->redis->consoleCommand(['RPUSH', 'pu-console-list', 'a', 'b']);
        $list = $this->redis->consoleCommand(['LRANGE', 'pu-console-list', '0', '-1']);
        $this->assertSame(['a', 'b'], $list);

        $this->assertSame('PONG', Helpers::mixedToString($this->redis->consoleCommand(['PING'])));
        $this->assertIsArray($this->redis->consoleCommand(['SLOWLOG', 'GET', '1']));
        $this->assertStringContainsString('redis_version', Helpers::mixedToString($this->redis->consoleCommand(['INFO', 'server'])));
    }

    /**
     * @throws Exception
     *
     * @throws Throwable
     */
    public function testConsoleUnknownCommand(): void {
        $this->expectException(Exception::class);
        $this->redis->consoleCommand(['NOTAREALCOMMAND']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function consoleAjax(string $command): array {
        $_GET['db'] = 10;
        $_GET['console'] = '';
        $_POST['command'] = $command;

        return $this->ajaxJson();
    }

    /**
     * @throws Exception
     */
    public function testConsoleAjaxWithInvalidCsrf(): void {
        $this->setCsrfToken(false);

        $this->assertSame('Invalid CSRF token.', $this->consoleAjax('PING')['error']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleAjaxExecutesCommands(): void {
        $this->setCsrfToken();

        $this->assertSame('OK', $this->consoleAjax('SET pu-console-ajax "hi there"')['output']);
        $this->assertSame('hi there', $this->consoleAjax('GET pu-console-ajax')['output']);

        $response = $this->consoleAjax('PING');
        $this->assertSame('PONG', $response['output']);
        $this->assertArrayNotHasKey('tab', $response);
    }

    /**
     * @throws Exception
     */
    public function testConsoleAjaxRejectsEmptyCommand(): void {
        $this->setCsrfToken();

        $this->assertSame('Empty command.', $this->consoleAjax('   ')['error']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleAjaxBlockedCommandSuggestsTab(): void {
        $this->setCsrfToken();

        $response = $this->consoleAjax('MONITOR');
        $this->assertStringContainsString('not allowed', (string) $response['error']);
        $this->assertStringContainsString('tab=profiler', (string) $response['tab']['url']);
        $this->assertStringContainsString('Profiler', (string) $response['tab']['label']);

        $this->assertStringContainsString('tab=pubsub', (string) $this->consoleAjax('psubscribe news.*')['tab']['url']);

        $response = $this->consoleAjax('SHUTDOWN');
        $this->assertStringContainsString('not allowed', (string) $response['error']);
        $this->assertArrayNotHasKey('tab', $response); // there is no tab to send the user to
    }

    /**
     * @throws Exception
     */
    public function testConsoleAjaxAllowedCommandSuggestsTab(): void {
        $this->setCsrfToken();

        $response = $this->consoleAjax('SLOWLOG GET');

        $this->assertArrayHasKey('output', $response);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertStringContainsString('tab=slowlog', (string) $response['tab']['url']);
        $this->assertStringContainsString('Slow Log', (string) $response['tab']['label']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleHistory(): void {
        @unlink($this->consoleHistoryFile());

        $this->setCsrfToken();

        foreach (['PING', 'DBSIZE', 'DBSIZE'] as $command) {
            $this->consoleAjax($command);
        }

        unset($_POST['command']);
        $_GET['history'] = '';

        $this->assertSame(['PING', 'DBSIZE'], $this->ajaxJson()['history']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleParsesQuotesAndEscapes(): void {
        $this->setCsrfToken();

        $this->assertSame('OK', $this->consoleAjax('SET pu-console-quote "hello world"')['output']);
        $this->assertSame('hello world', $this->consoleAjax('GET pu-console-quote')['output']);

        $this->assertSame('OK', $this->consoleAjax('SET pu-console-esc "a\nb"')['output']);
        $this->assertSame("a\nb", $this->consoleAjax('GET pu-console-esc')['output']);

        $this->consoleAjax('SET pu-console-dq "a\"b"');
        $this->assertSame('a"b', $this->consoleAjax('GET pu-console-dq')['output']);

        $this->consoleAjax("SET pu-console-sq 'a\\nb'");
        $this->assertSame('a\nb', $this->consoleAjax('GET pu-console-sq')['output']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleFormatsOutput(): void {
        $this->setCsrfToken();

        $this->consoleAjax('DEL pu-console-counter');
        $this->assertSame('(integer) 1', $this->consoleAjax('INCR pu-console-counter')['output']);

        $this->consoleAjax('DEL pu-console-missing');
        $this->assertSame('(nil)', $this->consoleAjax('GET pu-console-missing')['output']);

        $this->consoleAjax('DEL pu-console-list');
        $this->consoleAjax('RPUSH pu-console-list a b');
        $this->assertSame("1) \"a\"\n2) \"b\"", $this->consoleAjax('LRANGE pu-console-list 0 -1')['output']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleEmptyKeyName(): void {
        if (self::$is_cluster) {
            $this->markTestSkipped('A cluster cannot route an empty key name.');
        }

        $this->setCsrfToken();

        $this->assertSame('OK', $this->consoleAjax('SET "" pu-empty-name')['output']);
        $this->assertSame('pu-empty-name', $this->consoleAjax('GET ""')['output']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleBlocklistIsCaseInsensitive(): void {
        $this->setCsrfToken();

        $this->assertStringContainsString('not allowed', (string) $this->consoleAjax('subscribe ch')['error']);
    }

    /**
     * @throws Exception
     */
    public function testExportAndImport(): void {
        $keys_to_test = [
            'pu:test:key1' => ['value' => 'simple-value', 'ttl' => 120],
            'pu:test:key2' => ['value' => 'no-expire-value', 'ttl' => -1],
            'pu:test:key3' => ['value' => '{"json": "data"}', 'ttl' => 300],
        ];

        $export_keys_array = [];

        foreach ($keys_to_test as $key => $data) {
            $this->redis->set($key, $data['value']);

            if ($data['ttl'] > 0) {
                $this->redis->expire($key, $data['ttl']);
            }

            $export_keys_array[] = ['key' => $key, 'info' => ['ttl' => $this->redis->ttl($key)]];
        }

        $exported_json = Helpers::export(
            $export_keys_array,
            'redis_backup',
            fn (string $key): string => bin2hex($this->redis->dump($key)),
            true
        );

        $this->redis->flushDatabase();

        foreach (array_keys($keys_to_test) as $key) {
            $this->assertSame(0, $this->redis->exists($key));
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
            function (string $key): bool {
                $exists = $this->redis->exists($key);

                return is_int($exists) && $exists > 0;
            },
            function (string $key, string $value, int $ttl): bool {
                return $this->redis->restoreKeys($key, $ttl * 1000, hex2bin($value));
            }
        );

        foreach ($keys_to_test as $key => $data) {
            $this->assertSame(1, $this->redis->exists($key));
            $this->assertSame($data['value'], $this->redis->get($key));

            $restored_ttl = $this->redis->ttl($key);

            if ($data['ttl'] === -1) {
                $this->assertSame(-1, $restored_ttl);
            } else {
                $this->assertGreaterThan(0, $restored_ttl);
                $this->assertLessThanOrEqual($data['ttl'], $restored_ttl);
            }
        }

        unlink($tmp_file_path);
    }
}
