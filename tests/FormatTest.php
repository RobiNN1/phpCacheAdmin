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
use RobiNN\Pca\Format;

final class FormatTest extends TestCase {
    /**
     * @return Iterator<string, array{0: string, 1: int}>
     */
    public static function bytesProvider(): Iterator {
        yield 'bytes' => ['512,00B', 512];
        yield 'kilobytes' => ['1,50KB', 1536];
        yield 'megabytes' => ['127,38MB', 133_567_600];
        yield 'gigabytes' => ['2,50GB', 2_684_354_560];
        yield 'terabytes' => ['1,50TB', 1_649_267_441_664];
        yield 'zero' => ['0,00B', 0];
        yield 'just under a kilobyte' => ['1 023,00B', 1023];
        yield 'exactly one kilobyte' => ['1,00KB', 1024];
        yield 'exactly one megabyte' => ['1,00MB', 1_048_576];
        yield 'exactly one gigabyte' => ['1,00GB', 1_073_741_824];
        yield 'exactly one terabyte' => ['1,00TB', 1_099_511_627_776];
    }

    #[DataProvider('bytesProvider')]
    public function testBytes(string $expected, int $bytes): void {
        $this->assertSame($expected, Format::bytes($bytes));
    }

    /**
     * @return Iterator<string, array{0: int, 1: string}>
     */
    public static function iniSizeProvider(): Iterator {
        yield '10K' => [10_240, '10K'];
        yield '10M' => [10_485_760, '10M'];
        yield '10G' => [10_737_418_240, '10G'];
        yield 'lowercase unit' => [10_240, '10k'];
        yield 'plain number of bytes' => [16_777_216, '16777216'];
        yield 'zero' => [0, '0'];
    }

    #[DataProvider('iniSizeProvider')]
    public function testIniSizeToBytes(int $expected, string $size): void {
        $this->assertSame($expected, Format::iniSizeToBytes($size));
    }

    public function testSeconds(): void {
        $this->assertSame('1 hour', Format::seconds(3600));
        $this->assertSame('6 days 13 hours 41 minutes 14 seconds', Format::seconds(567_674));
    }

    /**
     * @return Iterator<string, array{0: string, 1: int}>
     */
    public static function timeDiffProvider(): Iterator {
        yield '1 second' => ['1 second ago', 1];
        yield '2 seconds' => ['2 seconds ago', 2];
        yield '1 minute' => ['1 minute ago', 60];
        yield '2 minutes' => ['2 minutes ago', 2 * 60];
        yield '1 hour' => ['1 hour ago', 60 * 60];
        yield '2 hours' => ['2 hours ago', 2 * 60 * 60];
        yield '1 day' => ['1 day ago', 24 * 60 * 60];
        yield '2 days' => ['2 days ago', 2 * 24 * 60 * 60];
        yield '1 week' => ['1 week ago', 7 * 24 * 60 * 60];
        yield '2 weeks' => ['2 weeks ago', 2 * 7 * 24 * 60 * 60];
        yield '1 month' => ['1 month ago', 30 * 24 * 60 * 60];
        yield '2 months' => ['2 months ago', 2 * 30 * 24 * 60 * 60];
        yield '1 year' => ['1 year ago', 365 * 24 * 60 * 60];
        yield '2 years' => ['2 years ago', 2 * 365 * 24 * 60 * 60];
    }

    #[DataProvider('timeDiffProvider')]
    public function testTimeDiff(string $expected, int $seconds_ago): void {
        $now = time();

        $this->assertSame($expected, Format::timeDiff($now - $seconds_ago, $now));
    }
}
