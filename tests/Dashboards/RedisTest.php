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
use ReflectionException;
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
     * @throws DashboardException|ReflectionException
     */
    protected function setUp(): void {
        $this->template = new Template();
        $this->dashboard = new RedisDashboard($this->template);
        $this->redis = $this->dashboard->connect(['host' => '127.0.0.1', 'database' => 10]);

        self::setValue($this->dashboard, 'redis', $this->redis);
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

        $_POST['delete'] = json_encode(array_map(static fn ($key) => base64_encode($key), [$key1, $key2, $key3]), JSON_THROW_ON_ERROR);

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
     * @throws Exception|ReflectionException
     */
    public function testSaveKey(): void {
        $key = 'pu-test-save';

        $_POST['redis_type'] = 'string';
        $_POST['key'] = $key;
        $_POST['value'] = 'test-value';
        $_POST['expire'] = -1;
        $_POST['encoder'] = 'none';

        Http::stopRedirect();
        self::callMethod($this->dashboard, 'saveKey');

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
        self::callMethod($this->dashboard, 'store', 'string', 'pu-test-type-string', 'svalue');
        self::callMethod($this->dashboard, 'store', 'set', 'pu-test-type-set', 'svalue1');
        self::callMethod($this->dashboard, 'store', 'set', 'pu-test-type-set', 'svalue2');
        self::callMethod($this->dashboard, 'store', 'set', 'pu-test-type-set', 'svalue3');

        self::callMethod($this->dashboard, 'store', 'list', 'pu-test-type-list', 'lvalue1');
        self::callMethod($this->dashboard, 'store', 'list', 'pu-test-type-list', 'lvalue2');
        self::callMethod($this->dashboard, 'store', 'list', 'pu-test-type-list', 'lvalue3');

        $_POST['score'] = 0;
        self::callMethod($this->dashboard, 'store', 'zset', 'pu-test-type-zset', 'zvalue1');
        $_POST['score'] = 1;
        self::callMethod($this->dashboard, 'store', 'zset', 'pu-test-type-zset', 'zvalue2');
        $_POST['score'] = 77;
        self::callMethod($this->dashboard, 'store', 'zset', 'pu-test-type-zset', 'zvalue3');

        $_POST['hash_key'] = 'hashkey1';
        self::callMethod($this->dashboard, 'store', 'hash', 'pu-test-type-hash', 'hvalue1');
        $_POST['hash_key'] = 'hashkey2';
        self::callMethod($this->dashboard, 'store', 'hash', 'pu-test-type-hash', 'hvalue2');
        $_POST['hash_key'] = 'hashkey3';
        self::callMethod($this->dashboard, 'store', 'hash', 'pu-test-type-hash', 'hvalue3');

        $this->redis->xAdd('pu-test-type-stream', '1670541476219-0', ['field1' => 'svalue1', 'field2' => 'svalue2']);
        $this->redis->xAdd('pu-test-type-stream', '1670541476219-1', ['field3' => 'svalue3']);

        $expected_original = [
            'string' => 'svalue',
            'set'    => ['svalue1', 'svalue2', 'svalue3'],
            'list'   => ['lvalue1', 'lvalue2', 'lvalue3'],
            'zset'   => [0 => 'zvalue1', 1 => 'zvalue2', 77 => 'zvalue3'],
            'hash'   => ['hashkey1' => 'hvalue1', 'hashkey2' => 'hvalue2', 'hashkey3' => 'hvalue3'],
            'stream' => [
                '1670541476219-0' => ['field1' => 'svalue1', 'field2' => 'svalue2'],
                '1670541476219-1' => ['field3' => 'svalue3'],
            ],
        ];

        foreach ($expected_original as $type_o => $value_o) {
            if (is_string($value_o)) {
                $this->assertSame($value_o, self::callMethod($this->dashboard, 'getAllKeyValues', $type_o, 'pu-test-type-'.$type_o));
            } else {
                $this->assertEqualsCanonicalizing($value_o, self::callMethod($this->dashboard, 'getAllKeyValues', $type_o, 'pu-test-type-'.$type_o));
            }
        }

        $delete = [
            'zset'   => 1,
            'hash'   => 'hashkey2',
            'stream' => '1670541476219-0',
        ];

        foreach ($delete as $type_d => $id) {
            switch ($type_d) {
                case 'zset':
                    $_GET['range'] = $id;
                    break;
                case 'hash':
                    $_GET['hash_key'] = $id;
                    break;
                case 'stream':
                    $_GET['stream_id'] = $id;
                    break;
            }

            self::callMethod($this->dashboard, 'deleteSubKey', $type_d, 'pu-test-type-'.$type_d);
        }

        $expected_new = [
            'zset'   => [0 => 'zvalue1', 77 => 'zvalue3'],
            'hash'   => ['hashkey1' => 'hvalue1', 'hashkey3' => 'hvalue3'],
            'stream' => ['1670541476219-1' => ['field3' => 'svalue3']],
        ];

        foreach ($expected_new as $type_n => $value_n) {
            $this->assertEqualsCanonicalizing($value_n, self::callMethod($this->dashboard, 'getAllKeyValues', $type_n, 'pu-test-type-'.$type_n));
        }

        foreach (['string', 'set', 'list', 'zset', 'hash', 'stream'] as $key) {
            $this->redis->del('pu-test-type-'.$key);
        }
    }
}
