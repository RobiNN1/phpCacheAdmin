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
}
