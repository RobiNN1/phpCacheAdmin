<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\OPCache;

use RobiNN\Pca\Dashboards\OPCache\OPCacheDashboard;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class OPCacheHealthTest extends TestCase {
    private OPCacheDashboard $dashboard;

    protected function setUp(): void {
        $this->dashboard = new OPCacheDashboard(new Template());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function buildStatus(array $overrides = []): array {
        return array_replace_recursive([
            'memory_usage'           => [
                'used_memory'               => 16_000_000,
                'wasted_memory'             => 0,
                'current_wasted_percentage' => 0.0,
            ],
            'opcache_statistics'     => [
                'oom_restarts'     => 0,
                'hash_restarts'    => 0,
                'num_cached_keys'  => 100,
                'max_cached_keys'  => 1000,
                'opcache_hit_rate' => 99.5,
            ],
            'interned_strings_usage' => [
                'used_memory' => 2_000_000,
                'buffer_size' => 8_000_000,
            ],
            'jit'                    => ['buffer_size' => 0],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function directives(array $overrides = []): array {
        return $overrides + [
                'opcache.memory_consumption'    => 64_000_000,
                'opcache.max_wasted_percentage' => 0.05,
                'opcache.file_cache'            => '',
            ];
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $directives
     *
     * @return array<string, array<string, mixed>>
     */
    private function checks(array $status = [], array $directives = []): array {
        $checks = [];

        foreach ($this->dashboard->getHealthChecks($this->buildStatus($status), $this->directives($directives)) as $check) {
            $checks[$check['name']] = $check;
        }

        return $checks;
    }

    public function testChecksAndTheirOrder(): void {
        $names = array_column($this->dashboard->getHealthChecks($this->buildStatus(), $this->directives()), 'name');

        $this->assertSame(['Memory usage', 'Key usage', 'Interned strings', 'JIT buffer', 'Wasted memory', 'Hit rate'], $names);
    }

    public function testChecksSkipUnavailableSections(): void {
        $status = $this->buildStatus();
        unset($status['interned_strings_usage'], $status['jit']);

        $names = array_column($this->dashboard->getHealthChecks($status, $this->directives()), 'name');

        $this->assertSame(['Memory usage', 'Key usage', 'Wasted memory', 'Hit rate'], $names);
    }

    public function testMemoryCheckHealthy(): void {
        $memory = $this->checks()['Memory usage'];

        $this->assertSame('healthy', $memory['status']);
        $this->assertEqualsWithDelta(25.0, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $memory['suggestion']);
    }

    public function testMemoryCheckWarning(): void {
        $memory = $this->checks(['memory_usage' => ['used_memory' => 30_000_000, 'wasted_memory' => 10_000_000]])['Memory usage'];

        $this->assertSame('warning', $memory['status']);
        $this->assertEqualsWithDelta(62.5, $memory['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('Increase opcache.memory_consumption.', (string) $memory['suggestion']);
    }

    public function testMemoryCheckOomRestarts(): void {
        $memory = $this->checks([
            'memory_usage'       => ['wasted_memory' => 2_000_000],
            'opcache_statistics' => ['oom_restarts' => 2],
        ])['Memory usage'];

        $this->assertSame('critical', $memory['status']);
        $this->assertStringContainsString('Out-of-memory restarts detected (2)', (string) $memory['suggestion']);
        $this->assertStringContainsString('Wasted:', (string) $memory['suggestion']);
    }

    public function testKeysCheckHealthy(): void {
        $keys = $this->checks()['Key usage'];

        $this->assertSame('healthy', $keys['status']);
        $this->assertEqualsWithDelta(10.0, $keys['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testKeysCheckNearTheLimit(): void {
        $keys = $this->checks(['opcache_statistics' => ['num_cached_keys' => 900]])['Key usage'];

        $this->assertSame('critical', $keys['status']);
        $this->assertEqualsWithDelta(90.0, $keys['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('Increase opcache.max_accelerated_files.', (string) $keys['suggestion']);
    }

    public function testKeysCheckHashRestarts(): void {
        $keys = $this->checks(['opcache_statistics' => ['hash_restarts' => 1]])['Key usage'];

        $this->assertSame('critical', $keys['status']);
        $this->assertStringContainsString('Hash restarts detected (1)', (string) $keys['suggestion']);
    }

    public function testInternedStringsCheckHealthy(): void {
        $interned = $this->checks()['Interned strings'];

        $this->assertSame('healthy', $interned['status']);
        $this->assertEqualsWithDelta(25.0, $interned['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testInternedStringsCheckCritical(): void {
        $interned = $this->checks(['interned_strings_usage' => ['used_memory' => 7_500_000]])['Interned strings'];

        $this->assertSame('critical', $interned['status']);
        $this->assertEqualsWithDelta(93.75, $interned['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('Increase opcache.interned_strings_buffer.', (string) $interned['suggestion']);
    }

    public function testInternedStringsCheckWithoutBuffer(): void {
        $this->assertArrayNotHasKey('Interned strings', $this->checks(['interned_strings_usage' => ['buffer_size' => 0]]));
    }

    public function testJitCheckDisabled(): void {
        $jit = $this->checks()['JIT buffer'];

        $this->assertSame('info', $jit['status']);
        $this->assertSame('JIT is disabled.', $jit['detail']);
    }

    public function testJitCheckNearFullBuffer(): void {
        $jit = $this->checks(['jit' => ['buffer_size' => 100_000_000, 'buffer_free' => 10_000_000]])['JIT buffer'];

        $this->assertSame('critical', $jit['status']);
        $this->assertEqualsWithDelta(90.0, $jit['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('Increase opcache.jit_buffer_size.', (string) $jit['suggestion']);
    }

    public function testWastedMemoryCheckHealthy(): void {
        $wasted = $this->checks()['Wasted memory'];

        $this->assertSame('healthy', $wasted['status']);
        $this->assertEqualsWithDelta(0.0, $wasted['utilization'], PHP_FLOAT_EPSILON);
    }

    public function testWastedMemoryCheckWarning(): void {
        $wasted = $this->checks(['memory_usage' => ['current_wasted_percentage' => 3.0]])['Wasted memory'];

        $this->assertSame('warning', $wasted['status']);
        $this->assertEqualsWithDelta(60.0, $wasted['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('approaching', (string) $wasted['suggestion']);
    }

    public function testWastedMemoryCheckCritical(): void {
        $wasted = $this->checks(['memory_usage' => ['current_wasted_percentage' => 6.0]])['Wasted memory'];

        $this->assertSame('critical', $wasted['status']);
        $this->assertEqualsWithDelta(100.0, $wasted['utilization'], PHP_FLOAT_EPSILON);
        $this->assertStringContainsString('auto-reset threshold', (string) $wasted['suggestion']);
    }

    public function testWastedMemoryCheckThresholdDisabled(): void {
        $wasted = $this->checks([], ['opcache.max_wasted_percentage' => 0])['Wasted memory'];

        $this->assertSame('healthy', $wasted['status']);
        $this->assertStringContainsString('auto-reset threshold disabled', (string) $wasted['detail']);
    }

    public function testHitRateCheckHealthy(): void {
        $hit_rate = $this->checks()['Hit rate'];

        $this->assertSame('healthy', $hit_rate['status']);
        $this->assertEqualsWithDelta(99.5, $hit_rate['utilization'], PHP_FLOAT_EPSILON);
        $this->assertSame('', $hit_rate['suggestion']);
    }

    public function testHitRateCheckCritical(): void {
        $hit_rate = $this->checks(['opcache_statistics' => ['opcache_hit_rate' => 40.0]])['Hit rate'];

        $this->assertSame('critical', $hit_rate['status']);
        $this->assertStringContainsString('hit rate can be normal', (string) $hit_rate['suggestion']);
    }

    public function testFileCacheCheck(): void {
        $file_cache = $this->checks([], ['opcache.file_cache' => '/tmp/opcache-file'])['File cache'];

        $this->assertSame('info', $file_cache['status']);
        $this->assertSame('/tmp/opcache-file', $file_cache['detail']);
    }

    public function testFileCacheCheckFileCacheOnly(): void {
        $file_cache = $this->checks([], [
            'opcache.file_cache'      => '/tmp/opcache-file',
            'opcache.file_cache_only' => true,
        ])['File cache'];

        $this->assertSame('/tmp/opcache-file (file_cache_only)', $file_cache['detail']);
    }
}
