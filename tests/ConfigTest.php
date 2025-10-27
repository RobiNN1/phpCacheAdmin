<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Config;

final class ConfigTest extends TestCase {
    protected function tearDown(): void {
        parent::tearDown();
        Config::reset();
    }

    public function testGetter(): void {
        $this->assertTrue(Config::get('true', true));
        $this->assertSame([], Config::get('array', []));
        $this->assertSame(88, Config::get('int', 88));
        $this->assertSame('d. m. Y H:i:s', Config::get('time-format', ''));
    }

    /**
     * @throws JsonException
     */
    public function testEnvGetter(): void {
        putenv('PCA_TESTENV-ARRAY='.json_encode(['item1' => 'value1', 'item2' => 'value2'], JSON_THROW_ON_ERROR));
        $this->assertSame('value1', Config::get('testenv-array', [])['item1']);
    }

    public function testEnvInt(): void {
        putenv('PCA_TESTENV-INT=10');

        $this->assertSame(10, Config::get('testenv-int', 2));
    }

    public function testEnvArray(): void {
        putenv('PCA_TESTENV-JSON={"local_cert":"path/to/redis.crt","local_pk":"path/to/redis.key","cafile":"path/to/ca.crt","verify_peer_name":false}');
        $this->assertEqualsCanonicalizing([
            'local_cert'       => 'path/to/redis.crt',
            'local_pk'         => 'path/to/redis.key',
            'cafile'           => 'path/to/ca.crt',
            'verify_peer_name' => false,
        ], Config::get('testenv-json', []));
    }

    public function testEnvOverride(): void {
        // default in config
        $this->assertSame('d. m. Y H:i:s', Config::get('time-format', ''));

        Config::reset();

        putenv('PCA_TIME-FORMAT=d. m. Y');

        $this->assertSame('d. m. Y', Config::get('time-format', ''));
    }
}
