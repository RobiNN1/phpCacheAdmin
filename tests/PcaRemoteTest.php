<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use RobiNN\PcaRemote;

if (!defined('PCA_REMOTE_NO_RUN')) {
    define('PCA_REMOTE_NO_RUN', true);
}

require_once __DIR__.'/../pca-remote.php';

final class PcaRemoteTest extends TestCase {
    private string|false $token_backup = false;

    protected function setUp(): void {
        $this->token_backup = getenv('PCA_REMOTE_TOKEN');
    }

    protected function tearDown(): void {
        putenv($this->token_backup === false ? 'PCA_REMOTE_TOKEN' : 'PCA_REMOTE_TOKEN='.$this->token_backup);

        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    private function skipWithoutApcu(): void {
        if (!extension_loaded('apcu') || ini_get('apc.enable_cli') !== '1') {
            $this->markTestSkipped('APC CLI is not enabled.');
        }
    }

    private function skipWithoutOpcache(): void {
        if (!extension_loaded('Zend OPcache') || @opcache_get_status(false) === false) {
            $this->markTestSkipped('OPcache is not enabled in CLI.');
        }
    }

    public function testCorrectTokenIsAuthorized(): void {
        putenv('PCA_REMOTE_TOKEN='.str_repeat('a', 32));

        $this->assertTrue(PcaRemote\authorized(str_repeat('a', 32)));
    }

    public function testWrongTokenIsNotAuthorized(): void {
        putenv('PCA_REMOTE_TOKEN='.str_repeat('a', 32));

        $this->assertFalse(PcaRemote\authorized(str_repeat('b', 32)));
    }

    public function testShortTokenIsNotAuthorized(): void {
        putenv('PCA_REMOTE_TOKEN=short');

        $this->assertFalse(PcaRemote\authorized('short'));
    }

    public function testMissingTokenIsNotAuthorized(): void {
        putenv('PCA_REMOTE_TOKEN');

        $this->assertFalse(PcaRemote\authorized(''));
    }

    public function testUnknownSection(): void {
        $this->assertNull(PcaRemote\section('redis', []));
    }

    public function testOpcacheSection(): void {
        $this->skipWithoutOpcache();

        $data = PcaRemote\section('opcache', []);

        $this->assertTrue($data['loaded']);
        $this->assertSame(phpversion('Zend OPcache'), $data['version']);
        $this->assertArrayHasKey('opcache_statistics', $data['status']);
        $this->assertArrayHasKey('directives', $data['configuration']);
    }

    public function testOpcacheScriptsOnlyWithTheFlag(): void {
        $this->skipWithoutOpcache();

        $this->assertArrayNotHasKey('scripts', PcaRemote\section('opcache', [])['status']);
        $this->assertArrayHasKey('scripts', PcaRemote\section('opcache', ['scripts' => '1'])['status']);
    }

    public function testApcuSection(): void {
        $this->skipWithoutApcu();

        $data = PcaRemote\section('apcu', []);

        $this->assertTrue($data['loaded']);
        $this->assertSame(phpversion('apcu'), $data['version']);
        $this->assertArrayHasKey('num_entries', $data['cache_info']);
        $this->assertArrayHasKey('seg_size', $data['sma_info']);
        $this->assertArrayHasKey('apc.shm_size', $data['ini']);
    }

    public function testApcuKeysOnlyWithTheFlag(): void {
        $this->skipWithoutApcu();

        apcu_store('pu-remote-key', 'secret-value');

        $this->assertArrayNotHasKey('keys', PcaRemote\section('apcu', []));
        $this->assertSame(['pu-remote-key'], array_column(PcaRemote\section('apcu', ['keys' => '1'])['keys'], 'key'));
    }

    /**
     * @throws JsonException
     */
    public function testApcuKeysWithoutValues(): void {
        $this->skipWithoutApcu();

        apcu_store('pu-remote-key', 'secret-value');

        $this->assertStringNotContainsString('secret-value', (string) json_encode(PcaRemote\section('apcu', ['keys' => '1']), JSON_THROW_ON_ERROR));
    }

    public function testRealpathSection(): void {
        realpath(__FILE__); // Puts it in the cache.
        $data = PcaRemote\section('realpath', []);

        $this->assertSame(realpath_cache_size(), $data['size']);
        $this->assertArrayHasKey(__FILE__, $data['entries']);
        $this->assertSame(ini_get('realpath_cache_ttl'), $data['ini']['realpath_cache_ttl']);
    }

    public function testBlacklistPatterns(): void {
        $file = (string) tempnam(sys_get_temp_dir(), 'pca_bl');
        file_put_contents($file, "; comment\n/var/www/cache/*\n\n/tmp/skip.php\n");

        try {
            $this->assertSame(['/var/www/cache/*', '/tmp/skip.php'], PcaRemote\blacklist($file)['patterns']);
        } finally {
            unlink($file);
        }
    }

    public function testUnreadableBlacklist(): void {
        $this->assertSame(['/nonexistent/pca-blacklist.txt'], PcaRemote\blacklist('/nonexistent/pca-blacklist.txt')['unreadable']);
    }
}
