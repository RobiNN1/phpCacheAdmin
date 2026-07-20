<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use JsonException;
use RobiNN\Pca\Config;
use RobiNN\Pca\ReadonlyMode;

final class ReadonlyModeTest extends TestCase {
    protected function setUp(): void {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void {
        putenv('PCA_READONLY');
        Config::reset();

        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }

    private function setReadonly(bool $enabled): void {
        putenv('PCA_READONLY='.($enabled ? 'true' : 'false'));
        Config::reset();
    }

    public function testDisabledKeepsActions(): void {
        $this->setReadonly(false);
        $_GET['delete'] = '1';

        $this->assertNull(ReadonlyMode::guard());
        $this->assertArrayHasKey('delete', $_GET);
    }

    public function testEnabled(): void {
        $this->setReadonly(true);

        $this->assertTrue(ReadonlyMode::enabled());
    }

    public function testStripsGetActions(): void {
        $this->setReadonly(true);
        $_GET['deleteall'] = '1';
        $_GET['form'] = 'new';

        $this->assertNull(ReadonlyMode::guard());
        $this->assertArrayNotHasKey('deleteall', $_GET);
        $this->assertArrayNotHasKey('form', $_GET);
    }

    public function testStripsPostActions(): void {
        $this->setReadonly(true);
        $_POST['submit'] = '1';
        $_POST['kill_client'] = '123';

        $this->assertNull(ReadonlyMode::guard());
        $this->assertArrayNotHasKey('submit', $_POST);
        $this->assertArrayNotHasKey('kill_client', $_POST);
    }

    public function testStripsImportFile(): void {
        $this->setReadonly(true);
        $_FILES['import'] = ['tmp_name' => '/tmp/upload', 'error' => UPLOAD_ERR_OK];

        $this->assertNull(ReadonlyMode::guard());
        $this->assertArrayNotHasKey('import', $_FILES);
    }

    public function testAjaxDeleteReturnsAlert(): void {
        $this->setReadonly(true);
        $_GET['ajax'] = '';
        $_GET['delete'] = '1';

        $response = ReadonlyMode::guard();

        $this->assertIsString($response);
        $this->assertStringContainsString(ReadonlyMode::MESSAGE, $response);
        $this->assertArrayNotHasKey('delete', $_GET);
    }

    /**
     * @throws JsonException
     */
    public function testAjaxConsoleReturnsJsonError(): void {
        $this->setReadonly(true);
        $_GET['ajax'] = '';
        $_GET['console'] = '';
        $_POST['command'] = 'FLUSHALL';

        $this->assertSame(json_encode(['error' => ReadonlyMode::MESSAGE], JSON_THROW_ON_ERROR), ReadonlyMode::guard());
        $this->assertArrayNotHasKey('command', $_POST);
    }

    public function testOptedOutDashboardKeepsItsActions(): void {
        $this->setReadonly(true);
        $_GET['deleteall'] = '1';
        $_GET['delete'] = '1';

        $this->assertNull(ReadonlyMode::guard(false));
        $this->assertArrayHasKey('deleteall', $_GET);
        $this->assertArrayHasKey('delete', $_GET);
    }

    public function testBlocksIsFalseForOptedOutDashboard(): void {
        $this->setReadonly(true);

        $this->assertTrue(ReadonlyMode::blocks());
        $this->assertFalse(ReadonlyMode::blocks(false));
    }

    public function testReadRequestPassesThrough(): void {
        $this->setReadonly(true);
        $_GET['dashboard'] = 'redis';
        $_GET['tab'] = 'keys';

        $this->assertNull(ReadonlyMode::guard());
        $this->assertSame('redis', $_GET['dashboard']);
    }
}
