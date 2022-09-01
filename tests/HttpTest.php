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

namespace Tests;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Http;

final class HttpTest extends TestCase {
    public function testQueryString(): void {
        $_SERVER['REQUEST_URI'] = '/?type=server';
        $this->assertSame('?type=server&param1=yes', Http::queryString([], ['param1' => 'yes']));

        $_SERVER['REQUEST_URI'] = '/?type=redis&server=6&p=3';
        $this->assertSame('?type=redis&server=6', Http::queryString(['server']));

        $_SERVER['REQUEST_URI'] = '/?type=redis&server=6&p=3&random=query&&view=key';
        $this->assertSame('?type=redis&server=6&view=key&key=test', Http::queryString(['server', 'view'], ['key' => 'test']));
    }

    public function testGetString(): void {
        $_GET['test-string'] = 'data';
        $this->assertSame('data', Http::get('test-string'));

        $_GET['test-string-default'] = null;
        $this->assertSame('default', Http::get('test-string-default', 'string', 'default'));

        $_GET['test-string-empty'] = '';
        $this->assertSame('', Http::get('test-string-empty'));
    }

    public function testGetInt(): void {
        $_GET['test-int'] = 4646;
        $this->assertSame(4646, Http::get('test-int', 'int'));

        $_GET['test-int-default'] = null;
        $this->assertSame(8888, Http::get('test-int-default', 'int', 8888));

        $_GET['test-int-empty'] = '';
        $this->assertSame(0, Http::get('test-int-empty', 'int'));
    }

    public function testPostString(): void {
        $_POST['test-string'] = 'data';
        $this->assertSame('data', Http::post('test-string'));

        $_POST['test-string-default'] = null;
        $this->assertSame('default', Http::post('test-string-default', 'string', 'default'));

        $_POST['test-string-empty'] = '';
        $this->assertSame('', Http::post('test-string-empty'));
    }

    public function testPostInt(): void {
        $_POST['test-int'] = 4646;
        $this->assertSame(4646, Http::post('test-int', 'int'));

        $_POST['test-int-default'] = null;
        $this->assertSame(8888, Http::post('test-int-default', 'int', 8888));

        $_POST['test-int-empty'] = '';
        $this->assertSame(0, Http::post('test-int-empty', 'int'));
    }
}
