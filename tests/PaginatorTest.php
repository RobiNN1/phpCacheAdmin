<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\Paginator;
use RobiNN\Pca\Template;

final class PaginatorTest extends TestCase {
    private Paginator $paginator;

    protected function setUp(): void {
        $items = [
            1 => ['key' => 'value1', 'title' => 'value1'],
            2 => ['key' => 'value2', 'title' => 'value2'],
            3 => ['key' => 'value3', 'title' => 'value3'],
            4 => ['key' => 'value4', 'title' => 'value4'],
        ];

        $_GET['p'] = 2;
        $_GET['pp'] = 2;

        $this->paginator = new Paginator(new Template(), $items);
    }

    public function testGetPaginated(): void {
        $expected = [
            3 => ['key' => 'value3', 'title' => 'value3'],
            4 => ['key' => 'value4', 'title' => 'value4'],
        ];

        $this->assertEqualsCanonicalizing($expected, $this->paginator->getPaginated());
    }

    public function testGetPages(): void {
        $this->assertEqualsCanonicalizing([0 => 1, 1 => 2], $this->paginator->getPages());
    }
}
