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

    private Redis|Predis|RedisCluster $redis;

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
    public function testSaveKey(): void {
        $key = 'pu-test-save';

        $_POST['redis_type'] = 'string';
        $_POST['key'] = $key;
        $_POST['value'] = 'test-value';
        $_POST['expire'] = -1;
        $_POST['encoder'] = 'none';

        Http::stopRedirect();
        $this->dashboard->saveKey();

        $this->assertSame('test-value', $this->redis->get($key));

        $this->redis->del($key);
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
        $this->dashboard->store('string', 'pu-test-type-string', 'svalue');

        $this->assertSame('svalue', $this->dashboard->getAllKeyValues('string', 'pu-test-type-string'));
    }

    /**
     * @throws Exception
     */
    public function testSetType(): void {
        $this->dashboard->store('set', 'pu-test-type-set', 'svalue1');
        $this->dashboard->store('set', 'pu-test-type-set', 'svalue2');
        $this->dashboard->store('set', 'pu-test-type-set', 'svalue3');

        $this->assertEqualsCanonicalizing(
            ['svalue1', 'svalue2', 'svalue3'],
            $this->dashboard->getAllKeyValues('set', 'pu-test-type-set')
        );

        $subkey = array_search('svalue2', $this->redis->sMembers('pu-test-type-set'), true);
        $this->dashboard->deleteSubKey('set', 'pu-test-type-set', $subkey);
        $this->assertEqualsCanonicalizing(
            ['svalue1', 'svalue3'],
            $this->dashboard->getAllKeyValues('set', 'pu-test-type-set')
        );
    }

    /**
     * @throws Exception
     */
    public function testListType(): void {
        $this->dashboard->store('list', 'pu-test-type-list', 'lvalue1');
        $this->dashboard->store('list', 'pu-test-type-list', 'lvalue2');
        $this->dashboard->store('list', 'pu-test-type-list', 'lvalue3');

        $this->assertEqualsCanonicalizing(
            ['lvalue1', 'lvalue2', 'lvalue3'],
            $this->dashboard->getAllKeyValues('list', 'pu-test-type-list')
        );

        $this->dashboard->deleteSubKey('list', 'pu-test-type-list', 1);
        $this->assertEqualsCanonicalizing(
            ['lvalue1', 'lvalue3'],
            $this->dashboard->getAllKeyValues('list', 'pu-test-type-list')
        );
    }

    /**
     * @throws Exception
     */
    public function testZSetType(): void {
        $this->dashboard->store('zset', 'pu-test-type-zset', 'zvalue1', '', ['zset_score' => 0]);
        $this->dashboard->store('zset', 'pu-test-type-zset', 'zvalue2', '', ['zset_score' => 1]);
        $this->dashboard->store('zset', 'pu-test-type-zset', 'zvalue3', '', ['zset_score' => 77]);

        $this->assertEqualsCanonicalizing(
            ['zvalue1', 'zvalue2', 'zvalue3'],
            $this->dashboard->getAllKeyValues('zset', 'pu-test-type-zset')
        );

        $this->dashboard->deleteSubKey('zset', 'pu-test-type-zset', 1);
        $this->assertEqualsCanonicalizing(
            ['zvalue1', 'zvalue3'],
            $this->dashboard->getAllKeyValues('zset', 'pu-test-type-zset')
        );
    }

    /**
     * @throws Exception
     */
    public function testHashType(): void {
        $this->dashboard->store('hash', 'pu-test-type-hash', 'hvalue1', '', ['hash_key' => 'hashkey1']);
        $this->dashboard->store('hash', 'pu-test-type-hash', 'hvalue2', '', ['hash_key' => 'hashkey2']);
        $this->dashboard->store('hash', 'pu-test-type-hash', 'hvalue3', '', ['hash_key' => 'hashkey3']);

        $this->assertEqualsCanonicalizing(
            ['hashkey1' => 'hvalue1', 'hashkey2' => 'hvalue2', 'hashkey3' => 'hvalue3'],
            $this->dashboard->getAllKeyValues('hash', 'pu-test-type-hash')
        );

        $this->dashboard->deleteSubKey('hash', 'pu-test-type-hash', 'hashkey2');
        $this->assertEqualsCanonicalizing(
            ['hashkey1' => 'hvalue1', 'hashkey3' => 'hvalue3'],
            $this->dashboard->getAllKeyValues('hash', 'pu-test-type-hash')
        );
    }

    /**
     * @throws Exception
     */
    public function testStreamType(): void {
        $this->dashboard->store('stream', 'pu-test-type-stream', '', '', [
            'stream_id'     => '1670541476219-0',
            'stream_fields' => ['field1' => 'stvalue1', 'field2' => 'stvalue2'],
        ]);
        $this->dashboard->store('stream', 'pu-test-type-stream', 'stvalue3', '', [
            'stream_id'    => '1670541476219-1',
            'stream_field' => 'field3',
        ]);

        $this->assertEqualsCanonicalizing(
            [
                '1670541476219-0' => ['field1' => 'stvalue1', 'field2' => 'stvalue2'],
                '1670541476219-1' => ['field3' => 'stvalue3'],
            ],
            $this->dashboard->getAllKeyValues('stream', 'pu-test-type-stream')
        );

        $this->dashboard->deleteSubKey('stream', 'pu-test-type-stream', '1670541476219-0');
        $this->assertEqualsCanonicalizing(
            ['1670541476219-1' => ['field3' => 'stvalue3']],
            $this->dashboard->getAllKeyValues('stream', 'pu-test-type-stream')
        );
    }

    /**
     * @throws Exception
     */
    public function testGetAllKeysTableView(): void {
        $this->redis->set('pu-test-table1', 'value1');
        $this->redis->set('pu-test-table2', 'value2');
        $_GET['s'] = 'pu-test-table*';
        $_GET['view'] = 'table';

        $result = $this->dashboard->getAllKeys();

        $info = [
            'bytes_size' => 0,
            'type'       => 'string',
            'ttl'        => 'Doesn\'t expire',
        ];

        $expected = [
            [
                'key'    => 'pu-test-table1',
                'base64' => true,
                'info'   => array_merge(['link_title' => 'pu-test-table1'], $info),
            ],
            [
                'key'    => 'pu-test-table2',
                'base64' => true,
                'info'   => array_merge(['link_title' => 'pu-test-table2'], $info),
            ],
        ];

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
        $_GET['view'] = 'tree';

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
                        'base64' => true,
                        'info'   => $info,
                    ],
                    [
                        'type'   => 'key',
                        'name'   => 'sub2',
                        'key'    => 'pu-test-tree1:sub2',
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
                'base64' => true,
                'info'   => $info,
            ],
        ];

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
}
