<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Format;

final class FormatTest extends TestCase {
    public function testBytes(): void {
        $this->assertSame('127,38MB', Format::bytes(133_567_600));
    }

    public function testIniSizeToBytes(): void {
        $this->assertSame(10_240, Format::iniSizeToBytes('10K'));
        $this->assertSame(10_485_760, Format::iniSizeToBytes('10M'));
        $this->assertSame(10_737_418_240, Format::iniSizeToBytes('10G'));
    }

    public function testSeconds(): void {
        $this->assertSame('1 hour', Format::seconds(3600));
        $this->assertSame('6 days 13 hours 41 minutes 14 seconds', Format::seconds(567_674));
    }

    public function testTimeDiff(): void {
        $current_time = 1720644659;

        $diffs = [
            '1 second ago'  => $current_time - 1,
            '2 seconds ago' => $current_time - 2,
            '1 minute ago'  => $current_time - 60,
            '2 minutes ago' => $current_time - (2 * 60),
            '1 hour ago'    => $current_time - (60 * 60),
            '2 hours ago'   => $current_time - (2 * 60 * 60),
            '1 day ago'     => $current_time - (24 * 60 * 60),
            '2 days ago'    => $current_time - (2 * 24 * 60 * 60),
            '1 week ago'    => $current_time - (7 * 24 * 60 * 60),
            '2 weeks ago'   => $current_time - (2 * 7 * 24 * 60 * 60),
            '1 month ago'   => $current_time - (30 * 24 * 60 * 60),
            '2 months ago'  => $current_time - (2 * 30 * 24 * 60 * 60),
            '1 year ago'    => $current_time - (365 * 24 * 60 * 60),
            '2 years ago'   => $current_time - (2 * 365 * 24 * 60 * 60),
        ];

        foreach ($diffs as $diff => $value) {
            $this->assertSame($diff, Format::timeDiff($value, $current_time));
        }
    }
}
