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
use RobiNN\Pca\Format;

final class FormatTest extends TestCase {
    public function testBytes(): void {
        $this->assertSame('127,38MB', Format::bytes(133_567_600));
    }

    public function testSeconds(): void {
        $this->assertSame('1 hour', Format::seconds(3600));
        $this->assertSame('6 days 13 hours 41 minutes 14 seconds', Format::seconds(567_674));
    }
}
