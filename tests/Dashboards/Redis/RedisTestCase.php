<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use Exception;
use JsonException;
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
    private RedisDashboard $dashboard;

    private Redis|Predis|RedisCluster|PredisCluster $redis;

    protected string $client;

    protected static bool $is_cluster = false;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$is_cluster = !empty(Config::get('redis')[0]['nodes']);
    }

    /**
     * @throws DashboardException
     */
    protected function setUp(): void {
        $this->dashboard = new RedisDashboard(new Template(), $this->client);

        if (self::$is_cluster) {
            $config = ['nodes' => Config::get('redis')[0]['nodes']];
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
        // A failed test would skip its own cleanup and leak these into the following tests.
        unset($_GET['pubsub'], $_GET['db'], $_POST['publish'], $_POST['channel'], $_POST['message'], $_POST['csrf_token']);

        $this->redis->flushDatabase();
    }

    /**
     * @throws Exception
     */
    public function testAjax(): void {
        $_GET['db'] = 10;

        $_GET['panels'] = '';
        $panels = $this->dashboard->ajax();
        $this->assertJson($panels);
        $this->assertStringNotContainsString('"error"', $panels);
        unset($_GET['panels']);

        $view_key = 'pu-test-ajax-view';
        $this->redis->set($view_key, 'view-data');
        $_GET['view'] = 'key';
        $_GET['key'] = $view_key;
        $rendered = $this->dashboard->ajax();
        $this->assertStringContainsString($view_key, $rendered);
        $this->assertStringContainsString('view-data', $rendered);
        unset($_GET['view'], $_GET['key']);
        $this->redis->del($view_key);

        $key = 'pu-test-ajax';
        $this->redis->set($key, 'data');

        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);

        $this->setCsrfToken(false);
        $this->assertSame(
            Helpers::alert('Invalid CSRF token.', 'error'),
            $this->dashboard->ajax()
        );
        $this->assertSame(1, $this->redis->exists($key));

        $this->setCsrfToken();
        $this->assertSame(
            Helpers::alert(sprintf('Key "%s" has been deleted.', $key), 'success'),
            $this->dashboard->ajax()
        );
        $this->assertSame(0, $this->redis->exists($key));

        unset($_GET['delete'], $_GET['db'], $_POST['delete'], $_POST['csrf_token']);
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

        $dir = Config::get('metricsdir', dirname(__DIR__, 3).'/tmp/metrics');
        @unlink($dir.'/redis_metrics_'.md5($server_name.Config::get('hash', 'pca')).'.db');
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
    public function testClusterValueAggregation(): void {
        $this->assertSame('8.0.0', $this->redis->combineValues('redis_version', ['8.0.0', '8.0.0'], null)); // identical values collapse
        $this->assertSame(30, $this->redis->combineValues('used_memory', [10, 20], ['used_memory'])); // listed keys are summed
        $this->assertEqualsWithDelta(1.5, $this->redis->combineValues('mem_fragmentation_ratio', [1.0, 2.0], null), PHP_FLOAT_EPSILON); // averaged
        $this->assertSame(20, $this->redis->combineValues('used_memory_peak', [10, 20], null)); // highest value
        $this->assertSame([10, 20], $this->redis->combineValues('unknown_key', [10, 20], null)); // kept per node

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
    public function testDeleteSubKey(): void {
        $this->saveData(['rtype' => 'hash', 'key' => 'pu-del-hash', 'value' => 'v1', 'hash_key' => 'f1']);
        $this->saveData(['rtype' => 'hash', 'key' => 'pu-del-hash', 'value' => 'v2', 'hash_key' => 'f2']);
        $this->dashboard->deleteSubKey('hash', 'pu-del-hash', 'f1');
        $this->assertSame(['f2' => 'v2'], $this->dashboard->getAllKeyValues('hash', 'pu-del-hash'));

        foreach (['a', 'b', 'c'] as $value) {
            $this->saveData(['rtype' => 'list', 'key' => 'pu-del-list', 'value' => $value, 'index' => '']);
        }

        $this->dashboard->deleteSubKey('list', 'pu-del-list', 1);
        $this->assertSame(['a', 'c'], $this->dashboard->getAllKeyValues('list', 'pu-del-list'));

        $this->saveData(['rtype' => 'set', 'key' => 'pu-del-set', 'value' => 'm1']);
        $this->saveData(['rtype' => 'set', 'key' => 'pu-del-set', 'value' => 'm2']);
        $this->dashboard->deleteSubKey('set', 'pu-del-set', 0); // members are addressed by their position
        $this->assertCount(1, $this->dashboard->getAllKeyValues('set', 'pu-del-set'));

        $this->saveData(['rtype' => 'zset', 'key' => 'pu-del-zset', 'value' => 'm1', 'score' => 1]);
        $this->saveData(['rtype' => 'zset', 'key' => 'pu-del-zset', 'value' => 'm2', 'score' => 2]);
        $this->dashboard->deleteSubKey('zset', 'pu-del-zset', 0); // ranges are sorted by score
        $this->assertSame(['m2'], $this->dashboard->getAllKeyValues('zset', 'pu-del-zset'));

        $this->saveData(['rtype' => 'stream', 'key' => 'pu-del-stream', 'value' => '{"f":"1"}', 'stream_id' => '*']);
        $this->saveData(['rtype' => 'stream', 'key' => 'pu-del-stream', 'value' => '{"f":"2"}', 'stream_id' => '*']);
        $ids = array_keys($this->dashboard->getAllKeyValues('stream', 'pu-del-stream'));
        $this->dashboard->deleteSubKey('stream', 'pu-del-stream', $ids[0]);
        $this->assertCount(1, $this->dashboard->getAllKeyValues('stream', 'pu-del-stream'));
    }

    /**
     * @throws Exception
     */
    public function testViewKeySubSearch(): void {
        $key = 'pu-test-subsearch-hash';

        $this->saveData(['rtype' => 'hash', 'key' => $key, 'hash_key' => 'apple', 'value' => 'redfruit']);
        $this->saveData(['rtype' => 'hash', 'key' => $key, 'hash_key' => 'banana', 'value' => 'yellowfruit']);
        $this->saveData(['rtype' => 'hash', 'key' => $key, 'hash_key' => 'carrot', 'value' => 'orangeveg']);

        $_GET['db'] = 10;
        $_GET['view'] = 'key';
        $_GET['key'] = $key;

        // The search box is shown for collections with more than one item.
        $rendered = $this->dashboard->ajax();
        $this->assertStringContainsString('id="subsearch_key"', $rendered);

        // Match by the subkey (hash field name).
        $_GET['subsearch'] = 'banana';
        $rendered = $this->dashboard->ajax();
        $this->assertStringContainsString('yellowfruit', $rendered);
        $this->assertStringNotContainsString('redfruit', $rendered);
        $this->assertStringNotContainsString('orangeveg', $rendered);

        // Match by the value.
        $_GET['subsearch'] = 'orangeveg';
        $rendered = $this->dashboard->ajax();
        $this->assertStringContainsString('orangeveg', $rendered);
        $this->assertStringNotContainsString('redfruit', $rendered);
        $this->assertStringNotContainsString('yellowfruit', $rendered);

        // No matches.
        $_GET['subsearch'] = 'zzzznomatch';
        $rendered = $this->dashboard->ajax();
        $this->assertStringContainsString('No items match your search.', $rendered);

        unset($_GET['db'], $_GET['view'], $_GET['key'], $_GET['subsearch']);
        $this->redis->del($key);
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

        $_GET['stream_id'] = array_key_last($this->redis->xRange($key, '-', '+'));
        $this->saveData([
            'rtype' => 'stream',
            'key'   => $key,
            'value' => json_encode(['field3' => 'edited'], JSON_THROW_ON_ERROR),
        ]);

        $all_values = array_values($this->dashboard->getAllKeyValues('stream', $key));
        $this->assertEquals(['field3' => 'edited'], $all_values[1]);

        unset($_GET['stream_id']);
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
                    [
                        'type'  => 'key',
                        'name'  => 'sub1',
                        'key'   => 'pu-test-tree1:sub1',
                        'items' => null,
                        'info'  => $info,
                    ],
                    [
                        'type'  => 'key',
                        'name'  => 'sub2',
                        'key'   => 'pu-test-tree1:sub2',
                        'items' => null,
                        'info'  => $info,
                    ],
                ],
                'expanded' => false,
                'count'    => 2,
            ],
            [
                'type'  => 'key',
                'name'  => 'pu-test-tree2',
                'key'   => 'pu-test-tree2',
                'items' => null,
                'info'  => $info,
            ],
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
     * @throws Exception
     */
    public function testAnalysis(): void {
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

        $analysis = $this->dashboard->analyzeKeys($keys, $this->redis->pipelineKeys($keys), 2, count($keys));

        $this->assertSame(204, $analysis['summary']['scanned']);
        $this->assertSame(5, $analysis['summary']['namespaces']);
        $this->assertCount(4, $analysis['tiles']);

        $this->assertSame('pu-analysis:zz-blob:huge', $analysis['top_memory'][0]['key']);
        $this->assertGreaterThan(50000, $analysis['top_memory'][0]['size']);

        $this->assertSame('pu-analysis:list:big', $analysis['top_length'][0]['key']);
        $this->assertSame(30, $analysis['top_length'][0]['items']);

        $cache = $this->findRow($analysis['namespaces'], 'pu-analysis:cache');
        $this->assertSame(200, $cache['count']);
        $this->assertGreaterThan(0, $cache['memory']);
        $this->assertSame(1, $this->findRow($analysis['namespaces'], '(no namespace)')['count']);

        $this->assertSame(203, $this->findRow($analysis['types'], 'string')['count']);
        $this->assertSame(1, $this->findRow($analysis['types'], 'list')['count']);

        $this->assertSame(203, $this->findRow($analysis['expiry'], 'No expiry')['count']);
        $this->assertSame(1, $this->findRow($analysis['expiry'], '< 1 hour')['count']);
        $this->assertSame(203, $analysis['summary']['no_expiry']['count']);

        $this->assertTrue($analysis['memory']);
    }

    /**
     * @throws Exception
     */
    public function testAnalysisRecommendations(): void {
        $this->redis->set('pu-rec:blob:big', str_repeat('x', 1_200_000));
        $this->redis->set('pu-rec:small:1', 'value');

        foreach (range(1, 5200) as $i) {
            $this->dashboard->store('list', 'pu-rec:list:long', 'item'.$i);
        }

        foreach (range(1, 200) as $i) {
            $this->dashboard->store('hash', 'pu-rec:hash:wide', 'v'.$i, '', ['hash_key' => 'f'.$i]);
        }

        $keys = $this->redis->scanKeys('pu-rec*', 1000);
        $pipeline = $this->redis->pipelineKeys($keys);

        $context = ['maxmemory' => 0, 'maxmemory_policy' => 'noeviction', 'hash_directive' => 'hash-max-listpack-entries', 'hash_limit' => 128];
        $found = $this->dashboard->analyzeKeys($keys, $pipeline, 2, count($keys), $context)['recommendations'];

        $big = $this->findRow($found, 'Big keys');
        $this->assertSame('warning', $big['status']); // over 1 MB but under the 10 MB mark
        $this->assertCount(1, $big['keys']);
        $this->assertSame('pu-rec:blob:big', $big['keys'][0]['key']);

        $long = $this->findRow($found, 'Long collections');
        $this->assertSame('pu-rec:list:long', $long['keys'][0]['key']);

        $hashes = $this->findRow($found, 'Hashes past the listpack limit');
        $this->assertSame('info', $hashes['status']);
        $this->assertSame('pu-rec:hash:wide', $hashes['keys'][0]['key']);
        $this->assertStringContainsString('128', (string) $hashes['directive']);

        $this->assertSame('warning', $this->findRow($found, 'Keys without a TTL')['status']);
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

        foreach (array_keys($pipeline) as $key) {
            $pipeline[$key]['size'] = 0;
        }

        $this->assertFalse($this->dashboard->analyzeKeys($keys, $pipeline, 2, 2, ['memory' => true])['memory']);
    }

    /**
     * @throws Exception
     */
    public function testAnalysisNoExpiryRecommendationDependsOnThePolicy(): void {
        $this->redis->set('pu-policy:key', 'value');

        $keys = $this->redis->scanKeys('pu-policy*', 100);
        $pipeline = $this->redis->pipelineKeys($keys);

        $status = function (array $context) use ($keys, $pipeline): ?string {
            $found = $this->dashboard->analyzeKeys($keys, $pipeline, 1, 1, $context)['recommendations'];

            foreach ($found as $recommendation) {
                if ($recommendation['name'] === 'Keys without a TTL') {
                    return $recommendation['status'];
                }
            }

            return null;
        };

        $this->assertSame('critical', $status(['maxmemory' => 1_000_000, 'maxmemory_policy' => 'volatile-lru']));
        $this->assertSame('warning', $status(['maxmemory' => 1_000_000, 'maxmemory_policy' => 'noeviction']));
        $this->assertSame('warning', $status(['maxmemory' => 0, 'maxmemory_policy' => 'noeviction']));
        $this->assertNull($status(['maxmemory' => 1_000_000, 'maxmemory_policy' => 'allkeys-lru']));
        $this->assertNull($status([]));
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

    public function testParseClientList(): void {
        $raw = "id=3 addr=127.0.0.1:50001 laddr=127.0.0.1:6379 name=worker age=10 idle=5 flags=N db=0 cmd=get user=default tot-mem=20512\r\n"
            ."id=4 addr=127.0.0.1:50002 name= age=1 idle=0 flags=O db=1 cmd=monitor user=alice tot-mem=100\n"
            ."id=5 addr=127.0.0.1:50003 name=app=worker age=1 idle=0 flags=N db=0 cmd=ping user=default tot-mem=100\n";

        $clients = $this->redis->parseClientList($raw, '4', '127.0.0.1:7000');

        $this->assertCount(3, $clients);

        // CLIENT SETNAME allows an "=" in the name, only the first one separates the field.
        $this->assertSame('app=worker', $clients[2]['name']);

        $this->assertSame('3', $clients[0]['id']);
        $this->assertSame('127.0.0.1:50001', $clients[0]['addr']);
        $this->assertSame('worker', $clients[0]['name']);
        $this->assertSame('20512', $clients[0]['tot-mem']);
        $this->assertFalse($clients[0]['self']);

        // An empty value still has to be a key, and the node is stamped on every row.
        $this->assertSame('', $clients[1]['name']);
        $this->assertSame('alice', $clients[1]['user']);
        $this->assertTrue($clients[1]['self']);
        $this->assertSame('127.0.0.1:7000', $clients[1]['node']);

        $this->assertSame([], $this->redis->parseClientList(''));
        $this->assertSame([], $this->redis->parseClientList('garbage without an id')); // nothing to address it by
    }

    /**
     * @throws Exception
     */
    public function testGetClients(): void {
        $clients = $this->redis->getClients();

        $this->assertNotEmpty($clients);

        $self = array_values(array_filter($clients, static fn (array $client): bool => $client['self'] === true));

        // The connection this test runs on has to be in there. A cluster holds one per node,
        // and each of those marks its own.
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

    /**
     * @return array{0: string, 1: int}
     */
    private function pubSubAddress(): array {
        $server = Config::get('redis')[0];

        if (self::$is_cluster) {
            [$host, $port] = explode(':', (string) $server['nodes'][0]) + [1 => '6379'];

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

    public function testParseNumSubReply(): void {
        $this->assertSame(['a' => 2, 'b' => 0], $this->redis->parseNumSubReply(['a', 2, 'b', 0]));
        $this->assertSame(['a' => 2], $this->redis->parseNumSubReply(['a' => '2'])); // phpredis can return an associative reply
        $this->assertSame([], $this->redis->parseNumSubReply([]));
    }

    /**
     * @throws Exception
     */
    public function testPubSubStatsAndPublish(): void {
        $channel = 'pu-pubsub-stats';

        $baseline = $this->redis->publishMessage($channel, 'baseline');
        $this->assertGreaterThanOrEqual(0, $baseline);

        [$host, $port] = $this->pubSubAddress();
        $subscriber = stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, 3);
        $this->assertNotFalse($subscriber, 'Could not open a raw subscriber socket: '.$errstr);
        stream_set_timeout($subscriber, 3);

        fwrite($subscriber, $this->respCommand('SUBSCRIBE', $channel));

        for ($i = 0; $i < 6; $i++) { // subscribe confirmation (*3, $9, subscribe, $len, channel, :1)
            fgets($subscriber);
        }

        $stats = $this->redis->pubSubStats('pu-pubsub-*');
        $this->assertSame(1, $stats['channels'][$channel] ?? null);
        $this->assertIsInt($stats['patterns']);

        $receivers = $this->redis->publishMessage($channel, 'hello-subscriber');

        if (!self::$is_cluster) {
            $this->assertSame($baseline + 1, $receivers);
        } else {
            // In a cluster, the reply only counts receivers connected to the node that ran PUBLISH.
            $this->assertGreaterThanOrEqual(0, $receivers);
        }

        // The message must reach the subscriber (in a cluster it is broadcast to all nodes).
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
     * @throws Exception
     */
    public function testPubSubAjax(): void {
        $_GET['db'] = 10;
        $_GET['pubsub'] = '';

        $stats = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('channels', $stats);
        $this->assertArrayHasKey('patterns', $stats);

        $_POST['publish'] = '1';
        $_POST['channel'] = 'pu-pubsub-ajax';
        $_POST['message'] = 'hi';

        $this->setCsrfToken(false);
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Invalid CSRF token.', $response['error']);

        $this->setCsrfToken();
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsInt($response['receivers'] ?? null); // external pattern subscribers may also receive it

        $_POST['channel'] = '';
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Channel name is required.', $response['error']);

        unset($_GET['db'], $_GET['pubsub'], $_POST['publish'], $_POST['channel'], $_POST['message'], $_POST['csrf_token']);
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
     * @throws Exception
     */
    public function testConsoleAjax(): void {
        $_GET['db'] = 10;
        $_GET['console'] = '';

        $this->setCsrfToken(false);
        $_POST['command'] = 'PING';
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Invalid CSRF token.', $response['error']);

        $this->setCsrfToken();
        $_POST['command'] = 'SET pu-console-ajax "hi there"';
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('OK', $response['output']);

        $_POST['command'] = 'GET pu-console-ajax';
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('hi there', $response['output']);

        $_POST['command'] = 'MONITOR';
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('not allowed', (string) $response['error']);

        $_POST['command'] = '   ';
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Empty command.', $response['error']);

        unset($_GET['db'], $_GET['console'], $_POST['command'], $_POST['csrf_token']);
    }

    /**
     * @throws Exception
     */
    public function testConsoleHistory(): void {
        $dir = Config::get('tmpdir', __DIR__.'/../../../tmp').'/console';
        $file = $dir.'/redis_history_'.md5(Helpers::getServerTitle(Config::get('redis')[0]).Config::get('hash', 'pca')).'.json';
        @unlink($file);

        $_GET['db'] = 10;
        $_GET['console'] = '';
        $this->setCsrfToken();

        foreach (['PING', 'DBSIZE', 'DBSIZE'] as $command) { // the repeated command must be stored only once
            $_POST['command'] = $command;
            $this->dashboard->ajax();
        }

        unset($_POST['command']);
        $_GET['history'] = '';
        $response = json_decode($this->dashboard->ajax(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['PING', 'DBSIZE'], $response['history']);

        @unlink($file);
        unset($_GET['db'], $_GET['console'], $_GET['history'], $_POST['csrf_token']);
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

            $this->redis->del($key);
        }

        unlink($tmp_file_path);
        unset($_FILES['import']);
    }
}
