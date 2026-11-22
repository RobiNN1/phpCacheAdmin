<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Memcached;

use JsonException;
use Memcached;
use RobiNN\Pca\Config;
use RobiNN\Pca\Dashboards\Memcached\MemcachedException;
use RobiNN\Pca\Dashboards\Memcached\MemcachedFlags;
use RobiNN\Pca\Dashboards\Memcached\PHPMem;
use Tests\TestCase;

final class MemcachedFlagsTest extends TestCase {
    public function testDescribe(): void {
        $this->assertSame(['PHP serialized', 'compressed (zlib)'], MemcachedFlags::describe(4 | 16 | 32));
        $this->assertSame(['string', 'compressed (fastlz)'], MemcachedFlags::describe(16 | 64));
        $this->assertSame(['integer', 'user flags 3'], MemcachedFlags::describe(1 | (3 << 16)));
        $this->assertSame([], MemcachedFlags::describe(0));
        $this->assertSame([], MemcachedFlags::describe(0x100)); // not a bit the extension uses
        $this->assertSame([], MemcachedFlags::describe(9)); // nor a type it knows
    }

    public function testDecompressZlib(): void {
        $original = str_repeat('zlib value ', 100);
        $stored = pack('V', strlen($original)).gzcompress($original);

        $this->assertSame($original, MemcachedFlags::decompress($stored, 16 | 32));
        $this->assertNull(MemcachedFlags::decompress($stored, 0));
        $this->assertNull(MemcachedFlags::decompress(pack('V', 5).'not compressed', 16 | 32));
    }

    public function testDecompressRejectsAWrongLength(): void {
        $original = str_repeat('zlib value ', 100);

        $this->assertNull(MemcachedFlags::decompress(pack('V', strlen($original) - 1).gzcompress($original), 16 | 32));
    }

    public function testFastlzLiteralsAndMatches(): void {
        // Level 1: a literal run "abc" (ctrl 2), then an overlapping match of 6 bytes 3 back (ctrl 4 << 5, distance code 2).
        $compressed = "\x02abc".chr(4 << 5).chr(2);

        $this->assertSame('abcabcabc', MemcachedFlags::fastlzDecompress($compressed));
        $this->assertNull(MemcachedFlags::fastlzDecompress("\x00a".chr(4 << 5).chr(10))); // a match before the start of the output
        $this->assertNull(MemcachedFlags::fastlzDecompress("\x05ab")); // a literal past the end
        $this->assertNull(MemcachedFlags::fastlzDecompress($compressed, 5)); // over the size limit
    }

    /**
     * Values written by the extension itself, both FastLZ levels (level 2 above 64 kB).
     *
     * @throws JsonException|MemcachedException
     */
    public function testValuesWrittenByTheExtension(): void {
        if (!extension_loaded('memcached')) {
            self::markTestSkipped('The memcached extension is not installed.');
        }

        $server = ['host' => (string) Config::get('memcached.0.host'), 'port' => (int) Config::get('memcached.0.port')];
        $raw = new PHPMem($server);

        $values = [
            'small' => str_repeat('hello ', 1000),
            'large' => json_encode(range(1, 20000), JSON_THROW_ON_ERROR),
            'mixed' => str_repeat('ab', 40000).str_repeat('xyz', 100).str_repeat('ab', 40000),
        ];

        foreach (['fastlz', 'zlib'] as $compression) {
            ini_set('memcached.compression_type', $compression); // read when the client is created

            $client = new Memcached();
            $client->addServer($server['host'], $server['port']);

            foreach ($values as $name => $value) {
                $key = 'pu-flags-'.$compression.'-'.$name;
                $client->set($key, $value);
                $item = $raw->getItem($key);

                $this->assertNotNull($item);
                $this->assertTrue(MemcachedFlags::isCompressed($item['flags']), $key);
                $this->assertSame($compression, MemcachedFlags::compression($item['flags']), $key);
                $this->assertSame($value, MemcachedFlags::decompress($item['value'], $item['flags']), $key);

                $raw->delete($key);
            }
        }

        ini_restore('memcached.compression_type');
    }
}
