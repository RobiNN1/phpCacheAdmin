<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Clients;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Predis;

final class PredisTest extends TestCase {
    private Predis $predis;

    protected function setUp(): void {
        $this->predis = new Predis(['host' => '127.0.0.1']);
    }

    /**
     * @return array<int, array<string>>
     */
    public static function keysProvider(): array {
        return [['string'], ['set'], ['list'], ['zset'], ['hash'], ['stream']];
    }

    /**
     * @dataProvider keysProvider
     *
     * @throws DashboardException
     */
    public function testGetType(string $key): void {
        $this->predis->set('pu-pred-test-string', 'value');
        $this->predis->sadd('pu-pred-test-set', ['value1', 'value2', 'value3']);
        $this->predis->rpush('pu-pred-test-list', ['value1', 'value2', 'value3']);
        $this->predis->zadd('pu-pred-test-zset', ['value1' => 0, 'value2' => 1, 'value3' => 2]);
        $this->predis->hset('pu-pred-test-hash', 'hashkey1', 'value1');
        $this->predis->hset('pu-pred-test-hash', 'hashkey2', 'value2');
        $this->predis->streamAdd('pu-pred-test-stream', '*', ['field1' => 'value1', 'field2' => 'value2']);
        $this->predis->streamAdd('pu-pred-test-stream', '*', ['field3' => 'value3']);

        $this->assertSame($key, $this->predis->getType('pu-pred-test-'.$key));
    }

    /**
     * @dataProvider keysProvider
     */
    public function testDelete(string $key): void {
        $this->predis->del('pu-pred-test-'.$key);
        $this->assertSame(0, $this->predis->exists('pu-pred-test-'.$key));
    }

    public function testGetInfo(): void {
        $this->assertArrayHasKey('redis_version', $this->predis->getInfo('server'));
    }
}
