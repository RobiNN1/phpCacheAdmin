<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Config;
use RobiNN\Pca\Http;

final class HttpTest extends TestCase {
    public function testQueryStringAppendsParams(): void {
        $_SERVER['REQUEST_URI'] = '/?dashboard=server';

        $this->assertSame('?dashboard=server&param1=yes&param2=0', Http::queryString([], ['param1' => 'yes', 'param2' => 0]));
    }

    public function testQueryStringKeepsOnlyGivenParams(): void {
        $_SERVER['REQUEST_URI'] = '/?dashboard=redis&server=6&p=3';

        $this->assertSame('?dashboard=redis&server=6', Http::queryString(['server']));
    }

    public function testQueryStringWithKeptAndAppendedParams(): void {
        $_SERVER['REQUEST_URI'] = '/?dashboard=redis&server=6&p=3&random=query&&view=key';

        $this->assertSame('?dashboard=redis&server=6&view=key&key=test', Http::queryString(['server', 'view'], ['key' => 'test']));
    }

    public function testGetString(): void {
        $_GET['test-string'] = 'data';
        $this->assertSame('data', Http::get('test-string', ''));

        $_GET['test-string-default'] = null;
        $this->assertSame('default', Http::get('test-string-default', 'default'));

        $_GET['test-string-empty'] = '';
        $this->assertSame('', Http::get('test-string-empty', ''));
    }

    public function testGetSanitizesSpecialChars(): void {
        $_GET['test-special'] = 'a<b>&"c';
        $this->assertSame('a&lt;b&gt;&amp;&quot;c', Http::get('test-special', ''));
    }

    public function testGetRawKeepsSpecialChars(): void {
        $_GET['test-raw'] = 'a<b>&"c';
        $this->assertSame('a<b>&"c', Http::get('test-raw', '', true));
    }

    public function testGetWithArrayValueReturnsDefault(): void {
        $_GET['test-array'] = ['a', 'b'];
        $this->assertSame('default', Http::get('test-array', 'default'));
        $this->assertSame(8, Http::get('test-array', 8));
        $this->assertSame('default', Http::get('test-array', 'default', true));
    }

    public function testGetInt(): void {
        $_GET['test-int'] = 4646;
        $this->assertSame(4646, Http::get('test-int', 0));

        $_GET['test-int-default'] = null;
        $this->assertSame(8888, Http::get('test-int-default', 8888));

        $_GET['test-int-empty'] = '';
        $this->assertSame(0, Http::get('test-int-empty', 0));
    }

    public function testPostString(): void {
        $_POST['test-string'] = 'data';
        $this->assertSame('data', Http::post('test-string', ''));

        $_POST['test-string-default'] = null;
        $this->assertSame('default', Http::post('test-string-default', 'default'));

        $_POST['test-string-empty'] = '';
        $this->assertSame('', Http::post('test-string-empty', ''));
    }

    public function testPostWithArrayValueReturnsDefault(): void {
        $_POST['test-array'] = ['a', 'b'];
        $this->assertSame('default', Http::post('test-array', 'default'));
        $this->assertSame(8, Http::post('test-array', 8));
    }

    public function testPostInt(): void {
        $_POST['test-int'] = 4646;
        $this->assertSame(4646, Http::post('test-int', 0));

        $_POST['test-int-default'] = null;
        $this->assertSame(8888, Http::post('test-int-default', 8888));

        $_POST['test-int-empty'] = '';
        $this->assertSame(0, Http::post('test-int-empty', 0));
    }

    /**
     * @return Iterator<string, array{0: bool, 1: string, 2: ?string, 3: ?string}>
     */
    public static function allowedHostProvider(): Iterator {
        yield 'no list allows any host' => [true, '', 'evil.example', 'http://evil.example'];
        yield 'listed host with port' => [true, '["127.0.0.1"]', '127.0.0.1:51234', null];
        yield 'listed host, same origin' => [true, '["127.0.0.1"]', '127.0.0.1:51234', 'http://127.0.0.1:51234'];
        yield 'case insensitive' => [true, '["LocalHost"]', 'localhost:8080', null];
        yield 'dns rebinding host' => [false, '["127.0.0.1","localhost"]', 'evil.example:51234', null];
        yield 'foreign origin' => [false, '["127.0.0.1"]', '127.0.0.1:51234', 'http://evil.example'];
        yield 'null origin' => [false, '["127.0.0.1"]', '127.0.0.1:51234', 'null'];
        yield 'missing host' => [false, '["127.0.0.1"]', null, null];
    }

    #[DataProvider('allowedHostProvider')]
    public function testIsAllowedHost(bool $expected, string $allowed, ?string $host, ?string $origin): void {
        $server = $_SERVER;

        putenv($allowed === '' ? 'PCA_ALLOWEDHOSTS' : 'PCA_ALLOWEDHOSTS='.$allowed);
        Config::reset();

        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_ORIGIN']);

        if ($host !== null) {
            $_SERVER['HTTP_HOST'] = $host;
        }

        if ($origin !== null) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
        }

        try {
            $this->assertSame($expected, Http::isAllowedHost());
        } finally {
            putenv('PCA_ALLOWEDHOSTS');
            Config::reset();
            $_SERVER = $server;
        }
    }
}
