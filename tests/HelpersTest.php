<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use Iterator;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

final class HelpersTest extends TestCase {
    private Template $template;

    protected function setUp(): void {
        $this->template = new Template();
    }

    public function testConvertTypesToString(): void {
        $input = [
            'string'       => 'value',
            'bool_true'    => true,
            'bool_false'   => false,
            'null_value'   => null,
            'empty_string' => '',
            'nested_array' => [
                'inner_bool'  => true,
                'inner_null'  => null,
                'inner_empty' => '',
            ],
            'integer'      => 123,
        ];

        $expected = [
            'string'       => 'value',
            'bool_true'    => 'true',
            'bool_false'   => 'false',
            'null_value'   => 'null',
            'empty_string' => 'empty',
            'nested_array' => [
                'inner_bool'  => 'true',
                'inner_null'  => 'null',
                'inner_empty' => 'empty',
            ],
            'integer'      => 123,
        ];

        $this->assertSame($expected, Helpers::convertTypesToString($input));
    }

    public function testMixedToStringWithDifferentTypes(): void {
        $this->assertSame('Hello, world!', Helpers::mixedToString('Hello, world!'));
        $this->assertSame('123', Helpers::mixedToString(123));
        $this->assertSame('1', Helpers::mixedToString(true));
        $this->assertSame('', Helpers::mixedToString(false));
        $this->assertSame('', Helpers::mixedToString(null));

        $array = ['a' => 1, 'b' => 2];
        $this->assertSame(serialize($array), Helpers::mixedToString($array));

        $object = (object) ['name' => 'John', 'age' => 30];
        $this->assertSame(serialize($object), Helpers::mixedToString($object));

        $resource = fopen('php://memory', 'rb');
        $this->assertSame((string) $resource, Helpers::mixedToString($resource));
        fclose($resource);
    }

    /**
     * @throws JsonException
     */
    public function testImport(): void {
        $_FILES['import'] = [
            'name'     => 'test.json',
            'type'     => 'application/json',
            'tmp_name' => __DIR__.'/test.json',
            'error'    => 0,
            'size'     => 1234,
        ];

        file_put_contents($_FILES['import']['tmp_name'], json_encode([
            ['key' => 'testkey1', 'value' => 'value1', 'ttl' => 3600],
            ['key' => 'testkey2', 'value' => 'value2', 'ttl' => 3600],
        ], JSON_THROW_ON_ERROR));

        $stored = [];

        Http::stopRedirect();

        Helpers::import(
            static fn ($key): false => false,
            static function (string $key, string $value, int $ttl) use (&$stored): void {
                $stored[] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
            }
        );

        unlink($_FILES['import']['tmp_name']);

        $this->assertSame('testkey1', $stored[0]['key']);
        $this->assertSame('value1', $stored[0]['value']);
        $this->assertSame(3600, $stored[0]['ttl']);
    }

    /**
     * @throws JsonException
     */
    public function testExport(): void {
        $keys = [
            ['key' => 'testkey1', 'items' => ['ttl' => 3600]],
            ['key' => 'testkey2', 'items' => ['ttl' => -1]],
        ];

        $output = Helpers::export($keys, 'export_test', static fn ($key) => $key, true);

        $expected = json_encode([
            ['key' => 'testkey1', 'ttl' => 3600, 'value' => 'testkey1'],
            ['key' => 'testkey2', 'ttl' => 0, 'value' => 'testkey2'],
        ], JSON_THROW_ON_ERROR);

        $this->assertJsonStringEqualsJsonString($expected, $output);
    }

    /**
     * @param array<int, array<string, mixed>> $keys
     * @param array<int, array<string, mixed>> $expected
     */
    #[DataProvider('sortKeysProvider')]
    public function testSortKeys(string $sortdir, string $sortcol, array $keys, array $expected): void {
        $_GET['sortdir'] = $sortdir;
        $_GET['sortcol'] = $sortcol;

        $this->assertSame($expected, Helpers::sortKeys($this->template, $keys));
    }

    public static function sortKeysProvider(): Iterator {
        yield 'no sorting' => [
            'sortdir'  => 'none',
            'sortcol'  => 'column1',
            'keys'     => [['items' => ['column1' => 'value1']], ['items' => ['column1' => 'value2']], ['items' => ['column1' => 'value3']],],
            'expected' => [['items' => ['column1' => 'value1']], ['items' => ['column1' => 'value2']], ['items' => ['column1' => 'value3']],],
        ];
        yield 'ascending sort' => [
            'sortdir'  => 'asc',
            'sortcol'  => 'column1',
            'keys'     => [['items' => ['column1' => 'value3']], ['items' => ['column1' => 'value1']], ['items' => ['column1' => 'value2']],],
            'expected' => [['items' => ['column1' => 'value1']], ['items' => ['column1' => 'value2']], ['items' => ['column1' => 'value3']],],
        ];
        yield 'descending sort' => [
            'sortdir'  => 'desc',
            'sortcol'  => 'column1',
            'keys'     => [['items' => ['column1' => 'value1']], ['items' => ['column1' => 'value2']], ['items' => ['column1' => 'value3']],],
            'expected' => [['items' => ['column1' => 'value3']], ['items' => ['column1' => 'value2']], ['items' => ['column1' => 'value1']],],
        ];
        yield 'ascending sort with integers' => [
            'sortdir'  => 'asc',
            'sortcol'  => 'column1',
            'keys'     => [['items' => ['column1' => 3]], ['items' => ['column1' => 1]], ['items' => ['column1' => 2]],],
            'expected' => [['items' => ['column1' => 1]], ['items' => ['column1' => 2]], ['items' => ['column1' => 3]],],
        ];
        yield 'descending sort with integers' => [
            'sortdir'  => 'desc',
            'sortcol'  => 'column1',
            'keys'     => [['items' => ['column1' => 1]], ['items' => ['column1' => 2]], ['items' => ['column1' => 3]],],
            'expected' => [['items' => ['column1' => 3]], ['items' => ['column1' => 2]], ['items' => ['column1' => 1]],],
        ];
    }
}
