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
use RobiNN\Pca\Value;

final class ValueTest extends TestCase {
    public function testFormat(): void {
        $value = '{"0":"test","test":"data"}';
        $array = ['test', 'test' => 'data'];

        $expected = '<pre class="json-code">{
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

    public function testDecoded(): void {
        $this->assertSame('gzcompress-data', Value::decoded(gzcompress('gzcompress-data')));
        $this->assertSame('gzencode-data', Value::decoded(gzencode('gzencode-data')));
        $this->assertSame('gzdeflate-data', Value::decoded(gzdeflate('gzdeflate-data')));
        $this->assertSame('random string', Value::decoded('random string'));
    }

    public function testFormatted(): void {
        $this->assertSame('{"0":"test","test":"data"}', Value::formatted(serialize(['test', 'test' => 'data'])));
        $this->assertSame('random string', Value::formatted('random string'));
    }

    public function testPrettyPrintJson(): void {
        $this->assertSame('1', Value::prettyPrintJson('1'));
        $this->assertSame('data', Value::prettyPrintJson('data'));
        $this->assertSame('<pre class="json-code">{
    &quot;0&quot;: &quot;test&quot;,
    &quot;test&quot;: &quot;data&quot;
}</pre>', Value::prettyPrintJson('{"0":"test","test":"data"}'));
    }

    public function testEncode(): void {
        $this->assertSame(gzcompress('gzcompress-data'), Value::converter('gzcompress-data', 'gzcompress', 'save'));
        $this->assertSame(gzencode('gzencode-data'), Value::converter('gzencode-data', 'gzencode', 'save'));
        $this->assertSame(gzdeflate('gzdeflate-data'), Value::converter('gzdeflate-data', 'gzdeflate', 'save'));
    }

    public function testDecode(): void {
        $this->assertSame('gzcompress-data', Value::converter(gzcompress('gzcompress-data'), 'gzcompress', 'view'));
        $this->assertSame('gzencode-data', Value::converter(gzencode('gzencode-data'), 'gzencode', 'view'));
        $this->assertSame('gzdeflate-data', Value::converter(gzdeflate('gzdeflate-data'), 'gzdeflate', 'view'));
        $this->assertSame('random string', Value::converter('random string', 'gzdeflate', 'view'));
    }
}
