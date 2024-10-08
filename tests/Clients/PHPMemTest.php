<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Clients;

use PHPUnit\Framework\Attributes\DataProvider;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\Memcached\Compatibility\PHPMem;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;
use Tests\TestCase;

final class PHPMemTest extends TestCase {
    private PHPMem $phpmem;

    protected function setUp(): void {
        $this->phpmem = new PHPMem([
            'host' => Config::get('memcached')[0]['host'],
            'port' => Config::get('memcached')[0]['port'],
        ]);
    }

    public function testIsConnected(): void {
        $this->assertTrue($this->phpmem->isConnected());
    }

    /**
     * @throws MemcachedException
     */
    #[DataProvider('keysProvider')]
    public function testSetGetKey(string $type, mixed $original, mixed $expected): void {
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
