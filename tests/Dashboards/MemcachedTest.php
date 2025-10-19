<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;
use RobiNN\Pca\Dashboards\Memcached\PHPMem;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class MemcachedTest extends TestCase {
    private Template $template;

    private MemcachedDashboard $dashboard;

    private PHPMem $memcached;

    /**
     * @throws DashboardException
     */
    protected function setUp(): void {
        $this->template = new Template();
        $this->dashboard = new MemcachedDashboard($this->template);
        $this->memcached = $this->dashboard->connect([
            'host' => Config::get('memcached')[0]['host'],
            'port' => Config::get('memcached')[0]['port'],
        ]);
        $this->dashboard->memcached = $this->memcached;
    }

    /**
     * @param array<int, string>|string $keys
     */
    private function deleteMemcachedKeys(array|string $keys): void {
        $this->deleteKeysHelper($this->template, $keys, fn (string $key): bool => $this->memcached->delete($key), true);
    }

    public function testIsConnected(): void {
        $this->assertTrue($this->memcached->isConnected());
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
        $this->assertSame(strtr($expected, ['\r\n' => "\r\n"]), $this->memcached->runCommand($command));
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
                    [
                        'type' => 'key',
                        'name' => 'sub1',
                        'key'  => 'pu-test-tree1%3Asub1',
                        'info' => $info,
                    ],
                    [
                        'type' => 'key',
                        'name' => 'sub2',
                        'key'  => 'pu-test-tree1%3Asub2',
                        'info' => $info,
                    ],
                ],
                'expanded' => false,
                'count'    => 2,
            ],
            [
                'type' => 'key',
                'name' => 'pu-test-tree2',
                'key'  => 'pu-test-tree2',
                'info' => $info,
            ],
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
                $value = $this->memcached->getKey(urldecode($key));

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
            fn (string $key, string $value, int $ttl): bool => $this->memcached->set(urldecode($key), base64_decode($value), $ttl)
        );

        foreach ($keys_to_test as $key => $data) {
            $this->assertTrue($this->memcached->exists($key));
            $this->assertSame($data['value'], $this->memcached->getKey($key));
            $this->memcached->delete($key);
        }

        unlink($tmp_file_path);
        unset($_FILES['import']);
    }
}
