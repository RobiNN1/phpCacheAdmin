<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\PredisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Cluster\RedisCluster;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

abstract class RedisTestCase extends TestCase {
    private Template $template;

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
        $this->template = new Template();
        $this->dashboard = new RedisDashboard($this->template, $this->client);

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
        $this->redis->flushDatabase();
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
        $this->deleteKeysHelper($this->template, $keys, function (string $key): bool {
            $delete_key = $this->redis->del($key);

            return is_int($delete_key) && $delete_key > 0;
        }, true);
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
                'key'    => 'pu-test-table1',
                'items'  => null,
                'base64' => true,
                'info'   => array_merge(['link_title' => 'pu-test-table1'], $info),
            ],
            [
                'key'    => 'pu-test-table2',
                'items'  => null,
                'base64' => true,
                'info'   => array_merge(['link_title' => 'pu-test-table2'], $info),
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
                        'type'   => 'key',
                        'name'   => 'sub1',
                        'key'    => 'pu-test-tree1:sub1',
                        'items'  => null,
                        'base64' => true,
                        'info'   => $info,
                    ],
                    [
                        'type'   => 'key',
                        'name'   => 'sub2',
                        'key'    => 'pu-test-tree1:sub2',
                        'items'  => null,
                        'base64' => true,
                        'info'   => $info,
                    ],
                ],
                'expanded' => false,
                'count'    => 2,
            ],
            [
                'type'   => 'key',
                'name'   => 'pu-test-tree2',
                'key'    => 'pu-test-tree2',
                'items'  => null,
                'base64' => true,
                'info'   => $info,
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
