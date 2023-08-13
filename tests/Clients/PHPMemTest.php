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

use RobiNN\Pca\Dashboards\Memcached\Compatibility\PHPMem;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;
use Tests\TestCase;

final class PHPMemTest extends TestCase {
    private PHPMem $phpmem;

    protected function setUp(): void {
        $this->phpmem = new PHPMem(['host' => '127.0.0.1', 'port' => 11211]);
    }

    public function testIsConnected(): void {
        $this->assertTrue($this->phpmem->isConnected());
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
        $key = 'pu-pmem-test-'.$type;
        $this->phpmem->set($key, $original);
        $this->assertSame($expected, $this->phpmem->getKey($key));
        $this->phpmem->delete($key);
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
