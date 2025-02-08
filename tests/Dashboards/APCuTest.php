<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards;

use PHPUnit\Framework\Attributes\DataProvider;
use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class APCuTest extends TestCase {
    private Template $template;

    private APCuDashboard $dashboard;

    public static function setUpBeforeClass(): void {
        if (ini_get('apc.enable_cli') !== '1') {
            self::markTestSkipped('APC CLI is not enabled. Skipping tests.');
        }
    }

    protected function setUp(): void {
        $this->template = new Template();
        $this->dashboard = new APCuDashboard($this->template);
    }

    /**
     * @param array<int, string>|string $keys
     */
    private function deleteApcuKeys(array|string $keys): void {
        $this->deleteKeysHelper($this->template, $keys, static fn (string $key): bool => apcu_delete($key), true);
    }

    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        apcu_store($key, 'data');
        $this->deleteApcuKeys($key);
        $this->assertFalse(apcu_exists($key));
    }

    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        apcu_store($key1, 'data1');
        apcu_store($key2, 'data2');
        apcu_store($key3, 'data3');

        $this->deleteApcuKeys([$key1, $key2, $key3]);

        $this->assertFalse(apcu_exists($key1));
        $this->assertFalse(apcu_exists($key2));
        $this->assertFalse(apcu_exists($key3));
    }

    #[DataProvider('keysProvider')]
    public function testSetGetKey(string $type, mixed $original, mixed $expected): void {
        apcu_store('pu-test-'.$type, $original);
        $this->assertSame($expected, Helpers::mixedToString(apcu_fetch('pu-test-'.$type)));
        apcu_delete('pu-test-'.$type);
    }

    public function testSaveKey(): void {
        $key = 'pu-test-save';

        $_POST['key'] = $key;
        $_POST['value'] = 'test-value';
        $_POST['encoder'] = 'none';

        Http::stopRedirect();
        $this->dashboard->saveKey();

        $this->assertSame('test-value', Helpers::mixedToString(apcu_fetch($key)));

        apcu_delete($key);
    }
}
