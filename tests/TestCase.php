<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

abstract class TestCase extends \PHPUnit\Framework\TestCase {
    /**
     * @return array<int, mixed>
     */
    public static function keysProvider(): array {
        return [
            ['string', 'phpCacheAdmin', 'phpCacheAdmin'],
            ['int', 23, '23'],
            ['float', 23.99, '23.99'],
            ['gzcompress', gzcompress('test'), gzcompress('test')],
            ['gzencode', gzencode('test'), gzencode('test')],
            ['gzdeflate', gzdeflate('test'), gzdeflate('test')],
            ['array', ['key1', 'key2'], 'a:2:{i:0;s:4:"key1";i:1;s:4:"key2";}'],
            ['object', (object) ['key1', 'key2'], 'O:8:"stdClass":2:{s:1:"0";s:4:"key1";s:1:"1";s:4:"key2";}'],
        ];
    }
}
