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

use ReflectionException;
use RobiNN\Pca\Value;

final class ValueTest extends TestCase {
    public function testFormat(): void {
        $value = '{"0":"test","test":"data"}';
        $array = ['test', 'test' => 'data'];

        $expected = '<pre>{
    &quot;0&quot;: &quot;test&quot;,
    &quot;test&quot;: &quot;data&quot;
}</pre>';

        $this->assertEqualsCanonicalizing(['test', null, false], Value::format('test'));
        $this->assertEqualsCanonicalizing([$expected, null, false], Value::format($value));
        $this->assertEqualsCanonicalizing([$expected, 'gzcompress', false], Value::format(gzcompress($value)));
        $this->assertEqualsCanonicalizing(['test', 'gzencode', false], Value::format(gzencode('test')));
        $this->assertEqualsCanonicalizing([$expected, 'gzdeflate', true], Value::format(gzdeflate(serialize($array))));
        $this->assertEqualsCanonicalizing([$expected, null, true], Value::format(serialize($array)));
    }

    /**
     * @throws ReflectionException
     */
    public function testDecoded(): void {
        $gzcompress = gzcompress('gzcompress-data');
        $this->assertSame('gzcompress-data', self::callMethod(new Value(), 'decoded', $gzcompress));

        $gzencode = gzencode('gzencode-data');
        $this->assertSame('gzencode-data', self::callMethod(new Value(), 'decoded', $gzencode));

        $gzdeflate = gzdeflate('gzdeflate-data');
        $this->assertSame('gzdeflate-data', self::callMethod(new Value(), 'decoded', $gzdeflate));

        $this->assertNull(self::callMethod(new Value(), 'decoded', 'random string'));
    }

    /**
     * @throws ReflectionException
     */
    public function testFormatted(): void {
        $value = serialize(['test', 'test' => 'data']);
        $this->assertSame('{"0":"test","test":"data"}', self::callMethod(new Value(), 'formatted', $value));

        $this->assertNull(self::callMethod(new Value(), 'formatted', 'random string'));
    }

    /**
     * @throws ReflectionException
     */
    public function testPrettyPrintJson(): void {
        $value = '{"0":"test","test":"data"}';

        $expected = '<pre>{
    &quot;0&quot;: &quot;test&quot;,
    &quot;test&quot;: &quot;data&quot;
}</pre>';

        $this->assertSame($expected, self::callMethod(new Value(), 'prettyPrintJson', $value));
        $this->assertSame('1', self::callMethod(new Value(), 'prettyPrintJson', '1'));
        $this->assertSame('data', self::callMethod(new Value(), 'prettyPrintJson', 'data'));
    }

    /**
     * @throws ReflectionException
     */
    public function testIsJson(): void {
        $this->assertTrue(self::callMethod(new Value(), 'isJson', '{"0":"test","test":"data"}'));
        $this->assertFalse(self::callMethod(new Value(), 'isJson', 'test'));
        $this->assertFalse(self::callMethod(new Value(), 'isJson', '1'));
        $this->assertFalse(self::callMethod(new Value(), 'isJson', 1));
    }

    public function testEncode(): void {
        $this->assertSame(gzcompress('gzcompress-data'), Value::encode('gzcompress-data', 'gzcompress'));
        $this->assertSame(gzencode('gzencode-data'), Value::encode('gzencode-data', 'gzencode'));
        $this->assertSame(gzdeflate('gzdeflate-data'), Value::encode('gzdeflate-data', 'gzdeflate'));
    }

    public function testDecode(): void {
        $this->assertSame('gzcompress-data', Value::decode(gzcompress('gzcompress-data'), 'gzcompress'));
        $this->assertSame('gzencode-data', Value::decode(gzencode('gzencode-data'), 'gzencode'));
        $this->assertSame('gzdeflate-data', Value::decode(gzdeflate('gzdeflate-data'), 'gzdeflate'));
        $this->assertSame('random string', Value::decode('random string', 'gzdeflate'));
    }
}
