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
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Template;

final class PaginatorTest extends TestCase {
    private Paginator $paginator;

    protected function setUp(): void {
        $items = [
            ['key' => 'value1', 'title' => 'value1'],
            ['key' => 'value2', 'title' => 'value2'],
            ['key' => 'value3', 'title' => 'value3'],
            ['key' => 'value4', 'title' => 'value4'],
        ];

        $_GET['pp'] = 2;

        $this->paginator = new Paginator(new Template(), $items);
    }

    public function testGetPaginated(): void {
        $expected = [
            ['key' => 'value1', 'title' => 'value1'],
            ['key' => 'value2', 'title' => 'value2'],
        ];

        $this->assertEqualsCanonicalizing($expected, $this->paginator->getPaginated());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetPages(): void {
        $this->assertEqualsCanonicalizing([0 => 1, 1 => 2], self::callMethod($this->paginator, 'getPages'));
    }
}
