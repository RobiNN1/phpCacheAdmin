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

namespace Tests\Clients;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Dashboards\Memcached\Compatibility\PHPMem;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;

final class PHPMemTest extends TestCase {
    private PHPMem $phpmem;

    protected function setUp(): void {
        $this->phpmem = new PHPMem(['host' => '127.0.0.1', 'port' => 11211]);
    }

    public function testIsConnected(): void {
        $this->assertTrue($this->phpmem->isConnected());
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
            $this->phpmem->set('pu-pmem-test-'.$key, $value['original']);
        }

        $this->assertSame($keys['string']['expected'], $this->phpmem->getKey('pu-pmem-test-string'));
        $this->assertSame($keys['int']['expected'], $this->phpmem->getKey('pu-pmem-test-int'));
        $this->assertSame($keys['float']['expected'], $this->phpmem->getKey('pu-pmem-test-float'));
        $this->assertSame($keys['bool']['expected'], $this->phpmem->getKey('pu-pmem-test-bool'));
        $this->assertSame($keys['null']['expected'], $this->phpmem->getKey('pu-pmem-test-null'));
        $this->assertSame($keys['array']['expected'], $this->phpmem->getKey('pu-pmem-test-array'));
        $this->assertSame($keys['object']['expected'], $this->phpmem->getKey('pu-pmem-test-object'));

        foreach ($keys as $key => $value) {
            $this->phpmem->delete('pu-pmem-test-'.$key);
        }
    }

    /**
     * @throws MemcachedException
     */
    public function testDeleteKey(): void {
        $key = 'pu-pmem-test-delete-key';

        $this->phpmem->set($key, 'data');

        $this->assertTrue($this->phpmem->delete($key));
    }

    /**
     * @throws MemcachedException
     */
    public function testGetServerStats(): void {
        $this->assertArrayHasKey('version', $this->phpmem->getServerStats());
    }
}
