<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards;

use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class APCuTest extends TestCase {
    private Template $template;

    private APCuDashboard $dashboard;

    protected function setUp(): void {
        if (!extension_loaded('apcu')) {
            $this->markTestSkipped('The apcu extension is not installed.');
        }

        $this->template = new Template();
        $this->dashboard = new APCuDashboard($this->template);
    }

    /**
     * @param array<int, string>|string $keys
     */
    private function deleteKeys($keys): void {
        $this->assertSame(
            Helpers::alert($this->template, (is_array($keys) ? 'Keys' : 'Key "'.$keys.'"').' has been deleted.', 'success'),
            Helpers::deleteKey($this->template, static fn (string $key): bool => apcu_delete($key), true, $keys)
        );
    }

    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        apcu_store($key, 'data');
        $this->deleteKeys($key);
        $this->assertFalse(apcu_exists($key));
    }

    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        apcu_store($key1, 'data1');
        apcu_store($key2, 'data2');
        apcu_store($key3, 'data3');

        $this->deleteKeys([$key1, $key2, $key3]);

        $this->assertFalse(apcu_exists($key1));
        $this->assertFalse(apcu_exists($key2));
        $this->assertFalse(apcu_exists($key3));
    }

    /**
     * @dataProvider keysProvider
     *
     * @param mixed $original
     * @param mixed $expected
     */
    public function testSetGetKey(string $type, $original, $expected): void {
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
