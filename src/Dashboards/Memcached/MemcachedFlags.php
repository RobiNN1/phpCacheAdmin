<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

/**
 * Client flags as the PHP memcached extension writes them.
 *
 * Bits 0-3 are the value type, bits 4-7 the compression and bits 16-31 the user flags (Memcached::OPT_USER_FLAGS).
 * A compressed value starts with its uncompressed length as a 4-byte integer.
 *
 * @link https://github.com/php-memcached-dev/php-memcached/blob/master/php_memcached.c
 */
class MemcachedFlags {
    public const COMPRESSED = 16;

    public const COMPRESSION_BITS = 0xF0;

    /**
     * Protection against a corrupted length prefix.
     */
    private const MAX_DECOMPRESSED = 64 * 1024 * 1024;

    /**
     * @var array<int, string>
     */
    private const TYPES = [
        0 => 'string',
        1 => 'integer',
        2 => 'float',
        3 => 'boolean',
        4 => 'PHP serialized',
        5 => 'igbinary',
        6 => 'JSON',
        7 => 'msgpack',
    ];

    /**
     * @var array<int, string>
     */
    private const COMPRESSIONS = [
        32  => 'zlib',
        64  => 'fastlz',
        128 => 'zstd',
    ];

    /**
     * Whether the flags can come from the PHP memcached extension, other clients use the bits differently.
     */
    public static function isPhpMemcached(int $flags): bool {
        return $flags > 0 && ($flags & 0xFF00) === 0 && ($flags & 0x0F) <= 7;
    }

    /**
     * E.g. ["PHP serialized", "compressed (zlib)"], empty when the flags are not from the PHP memcached extension.
     *
     * @return array<int, string>
     */
    public static function describe(int $flags): array {
        if (!self::isPhpMemcached($flags)) {
            return [];
        }

        $notes = [self::TYPES[$flags & 0x0F]];

        if (($flags & self::COMPRESSED) !== 0) {
            $notes[] = 'compressed ('.self::compression($flags).')';
        }

        if ($flags >> 16 !== 0) {
            $notes[] = 'user flags '.($flags >> 16);
        }

        return $notes;
    }

    public static function compression(int $flags): string {
        foreach (self::COMPRESSIONS as $bit => $name) {
            if (($flags & $bit) !== 0) {
                return $name;
            }
        }

        return 'unknown';
    }

    public static function isCompressed(int $flags): bool {
        return self::isPhpMemcached($flags) && ($flags & self::COMPRESSED) !== 0;
    }

    /**
     * Decompress a value compressed by the extension, null when it is not compressed or cannot be decompressed here.
     */
    public static function decompress(string $value, int $flags): ?string {
        if (!self::isCompressed($flags) || strlen($value) <= 4) {
            return null;
        }

        $length = (int) (unpack('V', substr($value, 0, 4))[1] ?? 0);
        $payload = substr($value, 4);

        if ($length <= 0 || $length > self::MAX_DECOMPRESSED) {
            return null;
        }

        $decompressed = match (self::compression($flags)) {
            'zlib', 'unknown' => @gzuncompress($payload, $length),
            'fastlz' => self::fastlzDecompress($payload, $length),
            'zstd' => function_exists('zstd_uncompress') ? @zstd_uncompress($payload) : false,
            default => false,
        };

        if (!is_string($decompressed) || strlen($decompressed) !== $length) {
            return null;
        }

        return $decompressed;
    }

    /**
     * FastLZ decompression (levels 1 and 2), the default compression of the extension.
     *
     * @link https://github.com/ariya/FastLZ
     */
    public static function fastlzDecompress(string $input, int $max_length = self::MAX_DECOMPRESSED): ?string {
        $length = strlen($input);

        if ($length === 0) {
            return null;
        }

        $level = (ord($input[0]) >> 5) + 1;

        if ($level !== 1 && $level !== 2) {
            return null;
        }

        $output = '';
        $ip = 1;
        $ctrl = ord($input[0]) & 31;

        while (true) {
            if ($ctrl >= 32) {
                $len = ($ctrl >> 5) - 1;
                $ofs = ($ctrl & 31) << 8;

                if ($level === 1) {
                    if ($len === 6) {
                        $len += ord($input[$ip++] ?? "\0");
                    }

                    $code = ord($input[$ip++] ?? "\0");
                    $ref = strlen($output) - $ofs - 1 - $code;
                } else {
                    if ($len === 6) {
                        do {
                            $code = ord($input[$ip++] ?? "\0");
                            $len += $code;
                        } while ($code === 255 && $ip < $length);
                    }

                    $code = ord($input[$ip++] ?? "\0");
                    $ref = strlen($output) - $ofs - 1 - $code;

                    if ($code === 255 && $ofs === (31 << 8)) { // far match with a 16-bit distance
                        $ofs = (ord($input[$ip] ?? "\0") << 8) + ord($input[$ip + 1] ?? "\0");
                        $ip += 2;
                        $ref = strlen($output) - $ofs - 8191 - 1;
                    }
                }

                $len += 3;

                if ($ref < 0 || $ip > $length || strlen($output) + $len > $max_length) {
                    return null;
                }

                $distance = strlen($output) - $ref;

                if ($distance >= $len) {
                    $output .= substr($output, $ref, $len);
                } else { // overlapping copy repeats the last $distance bytes
                    $output .= substr(str_repeat(substr($output, $ref, $distance), (int) ceil($len / $distance)), 0, $len);
                }
            } else {
                $ctrl++;

                if ($ip + $ctrl > $length || strlen($output) + $ctrl > $max_length) {
                    return null;
                }

                $output .= substr($input, $ip, $ctrl);
                $ip += $ctrl;
            }

            if ($ip >= $length) {
                return $output;
            }

            $ctrl = ord($input[$ip++]);
        }
    }
}
