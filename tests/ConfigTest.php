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
    /**
     * @var array<string, string|false>
     */
    private array $envBackup = [];

    protected function tearDown(): void {
        // Restore environment variables to their original state
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("$name=$value");
            }
        }

        // Reset the backup array for the next test
        $this->envBackup = [];

        Config::reset();
        parent::tearDown();
    }

    /**
     * Helper to set env var and back up the original value
     */
    private function setEnv(string $name, string $value): void {
        // Only backup the first time we touch this variable in a test
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }

        putenv("{$name}={$value}");
    }

    public function testGetter(): void {
        $this->assertTrue(Config::get('true', true));
        $this->assertSame([], Config::get('array', []));
        $this->assertSame(88, Config::get('int', 88));
        $this->assertSame('d. m. Y H:i:s', Config::get('timeformat', ''));
    }

    /**
     * @throws JsonException
     */
    public function testEnvGetter(): void {
        $this->setEnv('PCA_TESTENV-ARRAY', json_encode(['item1' => 'value1', 'item2' => 'value2'], JSON_THROW_ON_ERROR));
        $this->assertSame('value1', Config::get('testenv-array', [])['item1']);
    }

    public function testEnvInt(): void {
        $this->setEnv('PCA_TESTENV-INT', '10');

        $this->assertSame(10, Config::get('testenv-int', 2));
    }

    public function testEnvArray(): void {
        $this->setEnv('PCA_TESTENV-JSON', '{"local_cert":"path/to/redis.crt","local_pk":"path/to/redis.key","cafile":"path/to/ca.crt","verify_peer_name":false}');

        $this->assertEqualsCanonicalizing([
            'local_cert'       => 'path/to/redis.crt',
            'local_pk'         => 'path/to/redis.key',
            'cafile'           => 'path/to/ca.crt',
            'verify_peer_name' => false,
        ], Config::get('testenv-json', []));
    }

    public function testEnvOverride(): void {
        // default in config
        $this->assertSame('d. m. Y H:i:s', Config::get('timeformat', ''));

        Config::reset();

        $this->setEnv('PCA_TIMEFORMAT', 'd. m. Y');

        $this->assertSame('d. m. Y', Config::get('timeformat', ''));
    }

    public function testEnvNested(): void {
        $this->setEnv('PCA_REDIS_0_HOST', '127.0.0.1');
        $this->setEnv('PCA_REDIS_0_PORT', '6379');
        $this->setEnv('PCA_REDIS_2_HOST', 'localhost');
        $this->setEnv('PCA_REDIS_2_PORT', '6380');

        $redis_config = Config::get('redis', []);

        $this->assertSame('127.0.0.1', $redis_config[0]['host'] ?? null);
        $this->assertSame(6379, $redis_config[0]['port'] ?? null);

        $this->assertSame('localhost', $redis_config[2]['host'] ?? null);
        $this->assertSame(6380, $redis_config[2]['port'] ?? null);
    }

    public function testEnvCollisionWithScalar(): void {
        $this->setEnv('PCA_TIMEFORMAT', 'd. m. Y H:i:s');
        $this->setEnv('PCA_TIMEFORMAT_EXTRA', 'test');

        $this->assertSame('d. m. Y H:i:s', Config::get('timeformat', ''));
        $this->assertSame('test', Config::get('timeformat_extra'));
    }

    public function testEnvSnakeCase(): void {
        $this->setEnv('PCA_SOME_SNAKE_CASE_KEY', 'value');

        $this->assertSame('value', Config::get('some_snake_case_key'));
    }
}
