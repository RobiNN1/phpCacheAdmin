<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RobiNN\Pca\KeyFilters;

final class KeyFiltersTest extends TestCase {
    protected function tearDown(): void {
        $_GET = [];
    }

    public function testDefaults(): void {
        $filters = new KeyFilters(['nf', 'expin']);

        $this->assertSame('contains', $filters->values['match']);
        $this->assertFalse($filters->active());
        $this->assertFalse($filters->narrowed());
        $this->assertNull($filters->matcher());
        $this->assertSame(['s', 'match', 'nf', 'expin'], $filters->params());
    }

    public function testUnsupportedFiltersAreIgnored(): void {
        $_GET = ['nf' => '1', 'idle' => '60', 'type' => 'hash', 'match' => 'bogus'];
        $filters = new KeyFilters(['nf']);

        $this->assertTrue($filters->values['nf']);
        $this->assertSame(0, $filters->values['idle']);
        $this->assertSame('', $filters->values['type']);
        $this->assertSame('contains', $filters->values['match']);
    }

    public function testTypeMustBeAnOption(): void {
        $_GET = ['type' => 'hash'];
        $this->assertSame('hash', (new KeyFilters(['type'], ['hash' => 'Hash']))->values['type']);

        $_GET = ['type' => 'nope'];
        $this->assertSame('', (new KeyFilters(['type'], ['hash' => 'Hash']))->values['type']);
    }

    public function testMatchModes(): void {
        $_GET = ['s' => 'User.'];
        $contains = (new KeyFilters([]))->matcher();
        $this->assertNotNull($contains);
        $this->assertTrue($contains('app:user.1'));
        $this->assertFalse($contains('app:userx1'));

        $_GET = ['s' => 'app:', 'match' => 'prefix'];
        $prefix = (new KeyFilters([]))->matcher();
        $this->assertNotNull($prefix);
        $this->assertTrue($prefix('app:1'));
        $this->assertFalse($prefix('x:app:1'));

        $_GET = ['s' => '^a~b$', 'match' => 'regex'];
        $regex = (new KeyFilters([]))->matcher();
        $this->assertNotNull($regex);
        $this->assertTrue($regex('a~b'));
    }

    public function testInvalidRegexMatchesNothing(): void {
        $_GET = ['s' => '(', 'match' => 'regex'];
        $filters = new KeyFilters([]);
        $matcher = $filters->matcher();

        $this->assertNotNull($matcher);
        $this->assertFalse($matcher('('));
        $this->assertStringContainsString('Invalid regular expression', (string) $filters->error);
    }

    public function testMatches(): void {
        $_GET = ['noexp' => '1'];
        $filters = new KeyFilters(['noexp']);
        $this->assertTrue($filters->matches(['ttl' => -1]));
        $this->assertFalse($filters->matches(['ttl' => 10]));

        $_GET = ['expin' => '60', 'minsize' => '10', 'maxsize' => '100', 'idle' => '30', 'nf' => '1', 'type' => 'list'];
        $filters = new KeyFilters(['expin', 'minsize', 'maxsize', 'idle', 'nf', 'type'], ['list' => 'List']);
        $meta = ['ttl' => 30, 'size' => 50, 'idle' => 60, 'read' => false, 'type' => 'list'];

        $this->assertTrue($filters->matches($meta));
        $this->assertFalse($filters->matches(['ttl' => -1] + $meta));
        $this->assertFalse($filters->matches(['ttl' => 120] + $meta));
        $this->assertFalse($filters->matches(['size' => 5] + $meta));
        $this->assertFalse($filters->matches(['size' => 500] + $meta));
        $this->assertFalse($filters->matches(['idle' => 10] + $meta));
        $this->assertFalse($filters->matches(['read' => true] + $meta));
        $this->assertFalse($filters->matches(['type' => 'hash'] + $meta));
    }

    public function testView(): void {
        $_GET = ['dashboard' => 'redis', 'db' => '2', 's' => 'x', 'p' => '3'];
        $view = (new KeyFilters(['nf']))->view(7, ['nf' => 'Never hit'], null);

        $this->assertSame(7, $view['matching']);
        $this->assertTrue($view['open']);
        $this->assertNull($view['delete']);
        $this->assertSame('Never hit', $view['labels']['nf']);
        $this->assertSame(['dashboard' => 'redis', 'db' => '2'], $view['query']); // the page and the search are not kept
    }
}
