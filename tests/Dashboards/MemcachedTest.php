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

use JsonException;
use ReflectionException;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Memcached\Compatibility\Memcache;
use RobiNN\Pca\Dashboards\Memcached\Compatibility\Memcached;
use RobiNN\Pca\Dashboards\Memcached\Compatibility\PHPMem;
use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class MemcachedTest extends TestCase {
    private Template $template;

    private MemcachedDashboard $dashboard;

    /**
     * @var Memcached|Memcache|PHPMem
     */
    private $memcached;

    /**
     * @throws DashboardException|ReflectionException
     */
    protected function setUp(): void {
        $this->template = new Template();
        $this->dashboard = new MemcachedDashboard($this->template);
        $this->memcached = $this->dashboard->connect(['host' => '127.0.0.1']);

        self::setValue($this->dashboard, 'memcached', $this->memcached);
    }

    /**
     * @throws MemcachedException|JsonException
     */
    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        $this->memcached->set($key, 'data');

        $_POST['delete'] = json_encode($key, JSON_THROW_ON_ERROR);

        $this->assertSame(
            $this->template->render('components/alert', ['message' => 'Key "'.$key.'" has been deleted.']),
            Helpers::deleteKey($this->template, fn (string $key): bool => $this->memcached->delete($key))
        );
        $this->assertFalse($this->memcached->exists($key));
    }

    /**
     * @throws MemcachedException|JsonException
     */
    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        $this->memcached->set($key1, 'data1');
        $this->memcached->set($key2, 'data2');
        $this->memcached->set($key3, 'data3');

        $_POST['delete'] = json_encode([$key1, $key2, $key3], JSON_THROW_ON_ERROR);

        $this->assertSame(
            $this->template->render('components/alert', ['message' => 'Keys has been deleted.']),
            Helpers::deleteKey($this->template, fn (string $key): bool => $this->memcached->delete($key))
        );
        $this->assertFalse($this->memcached->exists($key1));
        $this->assertFalse($this->memcached->exists($key2));
        $this->assertFalse($this->memcached->exists($key3));
    }

    /**
     * @throws MemcachedException
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
                'original' => ['key1', 'key2'],
                'expected' => 'a:2:{i:0;s:4:"key1";i:1;s:4:"key2";}',
            ],
            'object' => [
                'original' => (object) ['key1', 'key2'],
                'expected' => 'O:8:"stdClass":2:{s:1:"0";s:4:"key1";s:1:"1";s:4:"key2";}',
            ],
        ];

        foreach ($keys as $key => $value) {
            $this->memcached->set('pu-test-'.$key, $value['original']);
        }

        $this->assertSame($keys['string']['expected'], $this->memcached->getKey('pu-test-string'));
        $this->assertSame($keys['int']['expected'], $this->memcached->getKey('pu-test-int'));
        $this->assertSame($keys['float']['expected'], $this->memcached->getKey('pu-test-float'));
        $this->assertSame($keys['bool']['expected'], $this->memcached->getKey('pu-test-bool'));
        $this->assertSame($keys['null']['expected'], $this->memcached->getKey('pu-test-null'));
        $this->assertSame($keys['gzip']['expected'], $this->memcached->getKey('pu-test-gzip'));
        $this->assertSame($keys['array']['expected'], $this->memcached->getKey('pu-test-array'));
        $this->assertSame($keys['object']['expected'], $this->memcached->getKey('pu-test-object'));

        foreach ($keys as $key => $value) {
            $this->memcached->delete('pu-test-'.$key);
        }
    }

    /**
     * @throws MemcachedException|ReflectionException
     */
    public function testSaveKey(): void {
        $key = 'pu-test-save';

        $_POST['key'] = $key;
        $_POST['value'] = 'test-value';
        $_POST['encoder'] = 'none';

        Http::stopRedirect();
        self::callMethod($this->dashboard, 'saveKey');

        $this->assertSame('test-value', $this->memcached->getKey($key));

        $this->memcached->delete($key);
    }

    /**
     * @throws MemcachedException
     */
    public function testGetServerStats(): void {
        $this->assertArrayHasKey('version', $this->memcached->getServerStats());
    }

    /**
     * @return array
     */
    public function provideCommandData(): array {
        return [
            'test set'    => ['STORED', 'set pu-test-rc-set 0 0 3\r\nidk'],
            'test get'    => ['VALUE pu-test-rc-set 0 3\r\nidk\r\nEND', 'get pu-test-rc-set'],
            'test delete' => ['DELETED', 'delete pu-test-rc-set'],

            'test add'             => ['STORED', 'add pu-test-rc-add 0 0 3\r\nidk'],
            'test replace'         => ['STORED', 'replace pu-test-rc-add 0 0 4\r\ntest'],
            'test replaced value'  => ['VALUE pu-test-rc-add 0 4\r\ntest\r\nEND', 'get pu-test-rc-add'],
            'test append'          => ['STORED', 'append pu-test-rc-add 0 0 2\r\naa'],
            'test appended value'  => ['VALUE pu-test-rc-add 0 6\r\ntestaa\r\nEND', 'get pu-test-rc-add'],
            'test prepend'         => ['STORED', 'prepend pu-test-rc-add 0 0 2\r\npp'],
            'test prepended value' => ['VALUE pu-test-rc-add 0 8\r\npptestaa\r\nEND', 'get pu-test-rc-add'],

            'test gat' => ['VALUE pu-test-rc-add 0 8\r\npptestaa\r\nEND', 'gat 700 pu-test-rc-add'],

            'test touch' => ['TOUCHED', 'touch pu-test-rc-add 0'],

            'test set int'  => ['STORED', 'set pu-test-rc-int 0 0 1\r\n1'],
            'test set incr' => ['6', 'incr pu-test-rc-int 5'],
            'test set decr' => ['3', 'decr pu-test-rc-int 3'],

            'test ms' => ['HD', 'ms pu-test-rc-ms 1\r\n4'],
            'test mg' => ['VA 1', 'mg pu-test-rc-ms v'],
            'test ma' => ['HD', 'ma pu-test-rc-ms'],

            'test cache_memlimit' => ['OK', 'cache_memlimit 100'],

            'test flush_all' => ['OK', 'flush_all'],
        ];
    }

    /**
     * @dataProvider provideCommandData
     *
     * @throws MemcachedException
     */
    public function testRunCommand(string $expected, string $command): void {
        $expected = strtr($expected, ['\r\n' => "\r\n"]);
        $this->assertSame($expected, $this->memcached->runCommand($command));
    }
}
