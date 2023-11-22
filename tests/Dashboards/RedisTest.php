<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards;

use Exception;
use Predis\Client as PredisClient;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class RedisTest extends TestCase {
    private Template $template;

    private RedisDashboard $dashboard;

    /**
     * @var Redis|Predis
     */
    private $redis;

    /**
     * @throws DashboardException
     */
    protected function setUp(): void {
        if (!class_exists(PredisClient::class) || !extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not installed.');
        }

        $this->template = new Template();
        $this->dashboard = new RedisDashboard($this->template);
        $this->redis = $this->dashboard->connect(['host' => '127.0.0.1', 'database' => 10]);
        $this->dashboard->redis = $this->redis;
    }

    /**
     * @param array<int, string>|string $keys
     *
     * @throws Exception
     */
    private function deleteKeys($keys): void {
        $this->assertSame(
            Helpers::alert($this->template, (is_array($keys) ? 'Keys' : 'Key "'.$keys.'"').' has been deleted.', 'success'),
            Helpers::deleteKey($this->template, function (string $key): bool {
                $delete_key = $this->redis->del($key);

                return is_int($delete_key) && $delete_key > 0;
            }, true, $keys)
        );
    }

    /**
     * @throws Exception
     */
    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        $this->redis->set($key, 'data');
        $this->deleteKeys($key);
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

        $this->deleteKeys([$key1, $key2, $key3]);

        $this->assertSame(0, $this->redis->exists($key1));
        $this->assertSame(0, $this->redis->exists($key2));
        $this->assertSame(0, $this->redis->exists($key3));
    }

    /**
     * @dataProvider keysProvider
     *
     * @param mixed $original
     * @param mixed $expected
     *
     * @throws Exception
     */
    public function testSetGetKey(string $type, $original, $expected): void {
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
    public function testTypes(): void {
        $this->dashboard->store('string', 'pu-test-type-string', 'svalue');

        $this->dashboard->store('set', 'pu-test-type-set', 'svalue1');
        $this->dashboard->store('set', 'pu-test-type-set', 'svalue2');
        $this->dashboard->store('set', 'pu-test-type-set', 'svalue3');

        $this->dashboard->store('list', 'pu-test-type-list', 'lvalue1');
        $this->dashboard->store('list', 'pu-test-type-list', 'lvalue2');
        $this->dashboard->store('list', 'pu-test-type-list', 'lvalue3');

        $this->dashboard->store('zset', 'pu-test-type-zset', 'zvalue1', '', ['zset_score' => 0]);
        $this->dashboard->store('zset', 'pu-test-type-zset', 'zvalue2', '', ['zset_score' => 1]);
        $this->dashboard->store('zset', 'pu-test-type-zset', 'zvalue3', '', ['zset_score' => 77]);

        $this->dashboard->store('hash', 'pu-test-type-hash', 'hvalue1', '', ['hash_key' => 'hashkey1']);
        $this->dashboard->store('hash', 'pu-test-type-hash', 'hvalue2', '', ['hash_key' => 'hashkey2']);
        $this->dashboard->store('hash', 'pu-test-type-hash', 'hvalue3', '', ['hash_key' => 'hashkey3']);

        $this->dashboard->store('stream', 'pu-test-type-stream', '', '', [
            'stream_id'     => '1670541476219-0',
            'stream_fields' => ['field1' => 'stvalue1', 'field2' => 'stvalue2'],
        ]);
        $this->dashboard->store('stream', 'pu-test-type-stream', 'stvalue3', '', [
            'stream_id'    => '1670541476219-1',
            'stream_field' => 'field3',
        ]);

        $expected_original = [
            'string' => 'svalue',
            'set'    => ['svalue1', 'svalue2', 'svalue3'],
            'list'   => ['lvalue1', 'lvalue2', 'lvalue3'],
            'zset'   => [0 => 'zvalue1', 1 => 'zvalue2', 77 => 'zvalue3'],
            'hash'   => ['hashkey1' => 'hvalue1', 'hashkey2' => 'hvalue2', 'hashkey3' => 'hvalue3'],
            'stream' => [
                '1670541476219-0' => ['field1' => 'stvalue1', 'field2' => 'stvalue2'],
                '1670541476219-1' => ['field3' => 'stvalue3'],
            ],
        ];

        foreach ($expected_original as $type_o => $value_o) {
            if (is_string($value_o)) {
                $this->assertSame($value_o, $this->dashboard->getAllKeyValues($type_o, 'pu-test-type-'.$type_o));
            } else {
                $this->assertEqualsCanonicalizing($value_o, $this->dashboard->getAllKeyValues($type_o, 'pu-test-type-'.$type_o));
            }
        }

        $delete = [
            'set'    => array_search('svalue2', $this->redis->sMembers('pu-test-type-set'), true),
            'list'   => 1,
            'zset'   => 1,
            'hash'   => 'hashkey2',
            'stream' => '1670541476219-0',
        ];

        foreach ($delete as $type_d => $id) {
            $this->dashboard->deleteSubKey($type_d, 'pu-test-type-'.$type_d, $id);
        }

        $expected_new = [
            'set'    => ['svalue1', 'svalue3'],
            'list'   => ['lvalue1', 'lvalue3'],
            'zset'   => [0 => 'zvalue1', 77 => 'zvalue3'],
            'hash'   => ['hashkey1' => 'hvalue1', 'hashkey3' => 'hvalue3'],
            'stream' => ['1670541476219-1' => ['field3' => 'stvalue3']],
        ];

        foreach ($expected_new as $type_n => $value_n) {
            $this->assertEqualsCanonicalizing($value_n, $this->dashboard->getAllKeyValues($type_n, 'pu-test-type-'.$type_n));
        }

        foreach (['string', 'set', 'list', 'zset', 'hash', 'stream'] as $key) {
            $this->redis->del('pu-test-type-'.$key);
        }
    }
}
