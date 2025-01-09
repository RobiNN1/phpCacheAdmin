<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use JsonException;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

abstract class TestCase extends \PHPUnit\Framework\TestCase {
    /**
     * @param array<int, string>|string $keys
     */
    public function deleteKeysHelper(Template $template, array|string $keys, callable $delete_key, bool $base64 = false): void {
        if ($base64) {
            $keys_b64 = is_array($keys) ? array_map(static fn (string $key): string => base64_encode($key), $keys) : base64_encode($keys);
        }

        try {
            $_POST['delete'] = json_encode($keys_b64 ?? $keys, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            //
        }

        $this->assertSame(
            Helpers::alert($template, (is_array($keys) ? 'Keys' : 'Key "'.$keys.'"').' has been deleted.', 'success'),
            Helpers::deleteKey($template, $delete_key, $base64)
        );
    }

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
