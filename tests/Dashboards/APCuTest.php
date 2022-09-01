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

namespace Tests\Dashboards;

use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class APCuTest extends TestCase {
    private Template $template;

    private APCuDashboard $apcu;

    protected function setUp(): void {
        $this->template = new Template();
        $this->apcu = new APCuDashboard($this->template);
    }

    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        apcu_store($key, 'data');

        $_GET['delete'] = $key;

        $this->assertSame(
            $this->template->render('components/alert', ['message' => 'Key "'.$key.'" has been deleted.']),
            self::callMethod($this->apcu, 'deleteKey')
        );
        $this->assertFalse(apcu_exists($key));
    }

    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        apcu_store($key1, 'data1');
        apcu_store($key2, 'data2');
        apcu_store($key3, 'data3');

        $_GET['delete'] = implode(',', [$key1, $key2, $key3]);

        $this->assertSame(
            $this->template->render('components/alert', ['message' => 'Keys has been deleted.']),
            self::callMethod($this->apcu, 'deleteKey')
        );
        $this->assertFalse(apcu_exists($key1));
        $this->assertFalse(apcu_exists($key2));
        $this->assertFalse(apcu_exists($key3));
    }

    public function testGetKey(): void {
        $keys = [
            'string' => ['original' => 'phpCacheAdmin', 'expected' => 'phpCacheAdmin'],
            'int'    => ['original' => 23, 'expected' => '23'],
            'float'  => ['original' => 23.99, 'expected' => '23.99'],
            'bool'   => ['original' => true, 'expected' => '1'],
            'null'   => ['original' => null, 'expected' => ''],
            'array'  => [
                'original' => ['key1', 'key2'],
                'expected' => 'a:2:{i:0;s:4:"key1";i:1;s:4:"key2";}',
            ],
            'object' => [
                'original' => (object) ['key1', 'key2'],
                'expected' => 'O:8:"stdClass":2:{s:1:"0";s:4:"key1";s:1:"1";s:4:"key2";}',
            ],
        ];

        foreach ($keys as $key => $value) {
            apcu_store('pu-test-'.$key, $value['original']);
        }

        $this->assertSame($keys['string']['expected'], self::callMethod($this->apcu, 'getKey', 'pu-test-string'));
        $this->assertSame($keys['int']['expected'], self::callMethod($this->apcu, 'getKey', 'pu-test-int'));
        $this->assertSame($keys['float']['expected'], self::callMethod($this->apcu, 'getKey', 'pu-test-float'));
        $this->assertSame($keys['bool']['expected'], self::callMethod($this->apcu, 'getKey', 'pu-test-bool'));
        $this->assertSame($keys['null']['expected'], self::callMethod($this->apcu, 'getKey', 'pu-test-null'));
        $this->assertSame($keys['array']['expected'], self::callMethod($this->apcu, 'getKey', 'pu-test-array'));
        $this->assertSame($keys['object']['expected'], self::callMethod($this->apcu, 'getKey', 'pu-test-object'));

        foreach ($keys as $key => $value) {
            apcu_delete('pu-test-'.$key);
        }
    }
}
