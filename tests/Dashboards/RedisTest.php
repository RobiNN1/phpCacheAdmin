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

namespace Tests\Dashboards;

use Exception;
use JsonException;
use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

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
        $this->template = new Template();
        $this->dashboard = new RedisDashboard($this->template);
        $this->redis = $this->dashboard->connect(['host' => '127.0.0.1', 'database' => 10]);
        $this->dashboard->redis = $this->redis;
    }

    /**
     * @throws Exception|JsonException
     */
    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        $this->redis->set($key, 'data');

        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);

        $this->assertSame(
            $this->template->render('components/alert', ['message' => 'Key "'.$key.'" has been deleted.']),
            Helpers::deleteKey($this->template, fn (string $key): bool => $this->redis->del($key) > 0, true)
        );
        $this->assertSame(0, $this->redis->exists($key));
    }

    /**
     * @throws Exception|JsonException
     */
    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        $this->redis->set($key1, 'data1');
        $this->redis->set($key2, 'data2');
        $this->redis->set($key3, 'data3');

        $_POST['delete'] = json_encode(array_map(static fn (string $key): string => base64_encode($key), [$key1, $key2, $key3]), JSON_THROW_ON_ERROR);

        $this->assertSame(
            $this->template->render('components/alert', ['message' => 'Keys has been deleted.']),
            Helpers::deleteKey($this->template, fn (string $key): bool => $this->redis->del($key) > 0, true)
        );
        $this->assertSame(0, $this->redis->exists($key1));
        $this->assertSame(0, $this->redis->exists($key2));
        $this->assertSame(0, $this->redis->exists($key3));
    }

    /**
     * @throws Exception
     */
    public function testSetGetKey(): void {
        $keys = [
            'string' => ['original' => 'phpCacheAdmin', 'expected' => 'phpCacheAdmin'],
            'int'    => ['original' => 23, 'expected' => '23'],
            'float'  => ['original' => 23.99, 'expected' => '23.99'],
            'bool'   => ['original' => true, 'expected' => '1'],
            'null'   => ['original' => null, 'expected' => ''],
            'gzip'   => ['original' => gzcompress('test'), 'expected' => gzcompress('test')],
            'array'  => [
                'original' => serialize(['key1', 'key2']),
                'expected' => 'a:2:{i:0;s:4:"key1";i:1;s:4:"key2";}',
            ],
            'object' => [
                'original' => serialize((object) ['key1', 'key2']),
                'expected' => 'O:8:"stdClass":2:{s:1:"0";s:4:"key1";s:1:"1";s:4:"key2";}',
            ],
        ];

        foreach ($keys as $key => $value) {
            $this->redis->set('pu-test-'.$key, $value['original']);
        }

        $this->assertSame($keys['string']['expected'], $this->redis->get('pu-test-string'));
        $this->assertSame($keys['int']['expected'], $this->redis->get('pu-test-int'));
        $this->assertSame($keys['float']['expected'], $this->redis->get('pu-test-float'));
        $this->assertSame($keys['bool']['expected'], $this->redis->get('pu-test-bool'));
        $this->assertSame($keys['null']['expected'], $this->redis->get('pu-test-null'));
        $this->assertSame($keys['gzip']['expected'], $this->redis->get('pu-test-gzip'));
        $this->assertSame($keys['array']['expected'], $this->redis->get('pu-test-array'));
        $this->assertSame($keys['object']['expected'], $this->redis->get('pu-test-object'));

        foreach ($keys as $key => $value) {
            $this->redis->del('pu-test-'.$key);
        }
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
