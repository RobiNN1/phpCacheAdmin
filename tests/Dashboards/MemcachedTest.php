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

use RobiNN\Pca\Dashboards\Memcached\Compatibility\Memcache;
use RobiNN\Pca\Dashboards\Memcached\Compatibility\Memcached;
use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class MemcachedTest extends TestCase {
    private Template $template;

    private MemcachedDashboard $dashboard;

    /**
     * @var Memcache|Memcached
     */
    private $memcached;

    protected function setUp(): void {
        $this->template = new Template();
        $this->dashboard = new MemcachedDashboard($this->template);
        $this->memcached = self::callMethod($this->dashboard, 'connect', ['host' => '127.0.0.1']);
    }

    public function testDeleteKey(): void {
        try {
            $key = 'pu-test-delete-key';

            $this->memcached->set($key, 'data');

            $_GET['delete'] = $key;

            $this->assertSame(
                $this->template->render('components/alert', ['message' => 'Key "'.$key.'" has been deleted.']),
                self::callMethod($this->dashboard, 'deleteKey', $this->memcached)
            );
            $this->assertFalse($this->memcached->exists($key));
        } catch (MemcachedException $e) {
            echo $e->getMessage();
        }
    }

    public function testDeleteKeys(): void {
        try {
            $key1 = 'pu-test-delete-key1';
            $key2 = 'pu-test-delete-key2';
            $key3 = 'pu-test-delete-key3';

            $this->memcached->set($key1, 'data1');
            $this->memcached->set($key2, 'data2');
            $this->memcached->set($key3, 'data3');

            $_GET['delete'] = implode(',', [$key1, $key2, $key3]);

            $this->assertSame(
                $this->template->render('components/alert', ['message' => 'Keys has been deleted.']),
                self::callMethod($this->dashboard, 'deleteKey', $this->memcached)
            );
            $this->assertFalse($this->memcached->exists($key1));
            $this->assertFalse($this->memcached->exists($key2));
            $this->assertFalse($this->memcached->exists($key3));
        } catch (MemcachedException $e) {
            echo $e->getMessage();
        }
    }

    public function testGetKey(): void {
        try {
            $keys = [
                'string' => ['original' => 'phpCacheAdmin', 'expected' => 'phpCacheAdmin'],
                'int'    => ['original' => 23, 'expected' => '23'],
                'float'  => ['original' => 23.99, 'expected' => '23.99'],
                'bool'   => ['original' => true, 'expected' => '1'],
                'null'   => ['original' => null, 'expected' => ''],
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
            $this->assertSame($keys['array']['expected'], $this->memcached->getKey('pu-test-array'));
            $this->assertSame($keys['object']['expected'], $this->memcached->getKey('pu-test-object'));

            foreach ($keys as $key => $value) {
                $this->memcached->delete('pu-test-'.$key);
            }
        } catch (MemcachedException $e) {
            echo $e->getMessage();
        }
    }

    public function testSaveKey(): void {
        try {
            $key = 'pu-test-save';

            $_POST['key'] = $key;
            $_POST['value'] = 'test-value';
            $_POST['encoder'] = 'none';

            Http::stopRedirect();
            self::callMethod($this->dashboard, 'saveKey', $this->memcached);

            $this->assertSame('test-value', $this->memcached->getKey($key));

            $this->memcached->delete($key);
        } catch (MemcachedException $e) {
            echo $e->getMessage();
        }
    }
}
