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

use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;
use Tests\TestCase;

final class PredisTest extends TestCase {
    private Predis $predis;

    protected function setUp(): void {
        $this->predis = new Predis(['host' => '127.0.0.1']);
    }

    /**
     * @throws DashboardException
     */
    public function testGetType(): void {
        $this->predis->set('pu-pred-test-string', 'value');
        $this->predis->sadd('pu-pred-test-set', ['value1', 'value2', 'value3']);
        $this->predis->rpush('pu-pred-test-list', ['value1', 'value2', 'value3']);
        $this->predis->zadd('pu-pred-test-zset', ['value1' => 0, 'value2' => 1, 'value3' => 2]);
        $this->predis->hset('pu-pred-test-hash', 'hashkey1', 'value1');
        $this->predis->hset('pu-pred-test-hash', 'hashkey2', 'value2');
        $this->predis->streamAdd('pu-pred-test-stream', '*', ['field1' => 'value1', 'field2' => 'value2']);
        $this->predis->streamAdd('pu-pred-test-stream', '*', ['field3' => 'value3']);

        $this->assertSame('string', $this->predis->getType('pu-pred-test-string'));
        $this->assertSame('set', $this->predis->getType('pu-pred-test-set'));
        $this->assertSame('list', $this->predis->getType('pu-pred-test-list'));
        $this->assertSame('zset', $this->predis->getType('pu-pred-test-zset'));
        $this->assertSame('hash', $this->predis->getType('pu-pred-test-hash'));
        $this->assertSame('stream', $this->predis->getType('pu-pred-test-stream'));
    }

    public function testDelete(): void {
        $this->predis->del('pu-pred-test-string');
        $this->predis->del('pu-pred-test-set');
        $this->predis->del('pu-pred-test-list');
        $this->predis->del('pu-pred-test-zset');
        $this->predis->del('pu-pred-test-hash');
        $this->predis->del('pu-pred-test-stream');

        $this->assertSame(0, $this->predis->exists('pu-pred-test-string'));
        $this->assertSame(0, $this->predis->exists('pu-pred-test-set'));
        $this->assertSame(0, $this->predis->exists('pu-pred-test-list'));
        $this->assertSame(0, $this->predis->exists('pu-pred-test-zset'));
        $this->assertSame(0, $this->predis->exists('pu-pred-test-hash'));
        $this->assertSame(0, $this->predis->exists('pu-pred-test-stream'));
    }

    public function testGetInfo(): void {
        $this->assertArrayHasKey('redis_version', $this->predis->getInfo('server'));
    }
}
