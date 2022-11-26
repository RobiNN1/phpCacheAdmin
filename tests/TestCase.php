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
use ReflectionMethod;
use ReflectionProperty;

abstract class TestCase extends \PHPUnit\Framework\TestCase {
    /**
     * Call private method.
     *
     * @param mixed ...$args
     *
     * @return mixed
     * @throws ReflectionException
     */
    protected static function callMethod(object $object, string $name, ...$args) {
        return (new ReflectionMethod($object, $name))->invokeArgs($object, $args);
    }

    /**
     * Set the value of private property.
     *
     * @param mixed $value
     *
     * @throws ReflectionException
     */
    protected static function setValue(object $object, string $name, $value): void {
        (new ReflectionProperty($object, $name))->setValue($object, $value);
    }
}
