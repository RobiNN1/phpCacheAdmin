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
     * @throws DashboardException
     */
    protected function setUp(): void {
        $this->template = new Template();
        $this->dashboard = new MemcachedDashboard($this->template);
        $this->memcached = $this->dashboard->connect(['host' => '127.0.0.1']);
        $this->dashboard->memcached = $this->memcached;
    }

    /**
     * @param array<int, string>|string $keys
     *
     * @throws MemcachedException
     */
    private function deleteKeys($keys): void {
        $this->assertSame(
            Helpers::alert($this->template, (is_array($keys) ? 'Keys' : 'Key "'.$keys.'"').' has been deleted.', 'success'),
            Helpers::deleteKey($this->template, fn (string $key): bool => $this->memcached->delete($key), false, $keys)
        );
    }

    /**
     * @throws MemcachedException
     */
    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        $this->memcached->set($key, 'data');
        $this->deleteKeys($key);
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

        $this->deleteKeys([$key1, $key2, $key3]);

        $this->assertFalse($this->memcached->exists($key1));
        $this->assertFalse($this->memcached->exists($key2));
        $this->assertFalse($this->memcached->exists($key3));
    }

    /**
     * @dataProvider keysProvider
     *
     * @param mixed $original
     * @param mixed $expected
     *
     * @throws MemcachedException
     */
    public function testSetGetKey(string $type, $original, $expected): void {
        $this->memcached->set('pu-test-'.$type, $original);
        $this->assertSame($expected, Helpers::mixedToString($this->memcached->getKey('pu-test-'.$type)));
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
     * @return array<string, array<int,string>>
     */
    public static function commandDataProvider(): array {
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
     * @dataProvider commandDataProvider
     *
     * @throws MemcachedException
     */
    public function testRunCommand(string $expected, string $command): void {
        $this->assertSame(strtr($expected, ['\r\n' => "\r\n"]), $this->memcached->runCommand($command));
    }
}
