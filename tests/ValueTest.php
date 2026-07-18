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
use RobiNN\Pca\Value;

final class ValueTest extends TestCase {
    private const PRETTY_JSON = '<pre class="json-code">{
    &quot;0&quot;: &quot;test&quot;,
    &quot;test&quot;: &quot;data&quot;
}</pre>';

    /**
     * @return Iterator<string, array{0: string, 1: string|null, 2: bool, 3: string}>
     */
    public static function formatProvider(): Iterator {
        $json = '{"0":"test","test":"data"}';
        $array = ['test', 'test' => 'data'];

        yield 'plain string' => ['test', null, false, 'test'];
        yield 'json' => [self::PRETTY_JSON, null, false, $json];
        yield 'gzcompressed json' => [self::PRETTY_JSON, 'gzcompress', false, gzcompress($json)];
        yield 'gzencoded string' => ['test', 'gzencode', false, gzencode('test')];
        yield 'gzdeflated serialized array' => [self::PRETTY_JSON, 'gzdeflate', true, gzdeflate(serialize($array))];
        yield 'serialized array' => [self::PRETTY_JSON, null, true, serialize($array)];
    }

    #[DataProvider('formatProvider')]
    public function testFormat(string $output, ?string $encoder, bool $is_serialized, string $value): void {
        $this->assertEqualsCanonicalizing([$output, $encoder, $is_serialized], Value::format($value));
    }

    public function testRawModeDoesNotDecode(): void {
        $compressed = gzcompress('{"0":"test","test":"data"}');

        [$value, $encoder, $is_formatted] = Value::format($compressed, Value::MODE_RAW);

        $this->assertSame('<pre class="json-code">'.htmlspecialchars($compressed).'</pre>', $value);
        $this->assertNull($encoder);
        $this->assertFalse($is_formatted);
    }

    public function testHexModeDoesNotDecode(): void {
        [$value, $encoder, $is_formatted] = Value::format(gzcompress('test'), Value::MODE_HEX);

        $this->assertStringContainsString('00000000  78', (string) $value);
        $this->assertNull($encoder);
        $this->assertFalse($is_formatted);
    }

    public function testUnknownModeFallsBackToFormatted(): void {
        $this->assertSame(Value::format('test'), Value::format('test'));
        $this->assertTrue(Value::isMode('hex'));
        $this->assertFalse(Value::isMode('nonsense'));
    }

    public function testHexDump(): void {
        $this->assertSame(
            "00000000  7b 22 61 22 3a 31 7d 00  ff                      |{\"a\":1}..|\n",
            Value::hexDump("{\"a\":1}\x00\xff")
        );

        $this->assertSame(
            "00000000  41 41 41 41 41 41 41 41  41 41 41 41 41 41 41 41 |AAAAAAAAAAAAAAAA|\n".
            "00000010  42 42                                            |BB|\n",
            Value::hexDump(str_repeat('A', 16).'BB')
        );

        $this->assertSame("(empty)\n", Value::hexDump(''));
    }

    public function testHexDumpIsCapped(): void {
        $dump = Value::hexDump(str_repeat('x', Value::HEX_LIMIT + 4_096));

        $this->assertStringContainsString('of ', $dump);
        $this->assertStringContainsString('bytes shown', $dump);

        $this->assertStringContainsString(sprintf('%08x', Value::HEX_LIMIT - 16), $dump);
        $this->assertStringNotContainsString(sprintf('%08x', Value::HEX_LIMIT), $dump);
    }

    /**
     * @return Iterator<string, array{0: string, 1: string}>
     */
    public static function decodedProvider(): Iterator {
        yield 'gzcompress' => ['gzcompress-data', gzcompress('gzcompress-data')];
        yield 'gzencode' => ['gzencode-data', gzencode('gzencode-data')];
        yield 'gzdeflate' => ['gzdeflate-data', gzdeflate('gzdeflate-data')];
        yield 'plain string stays as-is' => ['random string', 'random string'];
    }

    #[DataProvider('decodedProvider')]
    public function testDecoded(string $expected, string $value): void {
        $this->assertSame($expected, Value::decoded($value));
    }

    public function testFormatted(): void {
        $this->assertSame('{"0":"test","test":"data"}', Value::formatted(serialize(['test', 'test' => 'data'])));
        $this->assertSame('random string', Value::formatted('random string'));
    }

    public function testPrettyPrintJson(): void {
        $this->assertSame('1', Value::prettyPrintJson('1'));
        $this->assertSame('data', Value::prettyPrintJson('data'));
        $this->assertSame(self::PRETTY_JSON, Value::prettyPrintJson('{"0":"test","test":"data"}'));
    }

    /**
     * @return Iterator<string, array{0: string, 1: string, 2: string}>
     */
    public static function encodeProvider(): Iterator {
        yield 'gzcompress' => [gzcompress('gzcompress-data'), 'gzcompress-data', 'gzcompress'];
        yield 'gzencode' => [gzencode('gzencode-data'), 'gzencode-data', 'gzencode'];
        yield 'gzdeflate' => [gzdeflate('gzdeflate-data'), 'gzdeflate-data', 'gzdeflate'];
    }

    #[DataProvider('encodeProvider')]
    public function testEncode(string $expected, string $value, string $encoder): void {
        $this->assertSame($expected, Value::converter($value, $encoder, 'save'));
    }

    /**
     * @return Iterator<string, array{0: string, 1: string, 2: string}>
     */
    public static function decodeProvider(): Iterator {
        yield 'gzcompress' => ['gzcompress-data', gzcompress('gzcompress-data'), 'gzcompress'];
        yield 'gzencode' => ['gzencode-data', gzencode('gzencode-data'), 'gzencode'];
        yield 'gzdeflate' => ['gzdeflate-data', gzdeflate('gzdeflate-data'), 'gzdeflate'];
        yield 'not encoded data stays as-is' => ['random string', 'random string', 'gzdeflate'];
    }

    #[DataProvider('decodeProvider')]
    public function testDecode(string $expected, string $value, string $encoder): void {
        $this->assertSame($expected, Value::converter($value, $encoder, 'view'));
    }
}
