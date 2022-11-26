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
        $method = new ReflectionMethod($object, $name);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    /**
     * Set the value of private property.
     *
     * @param mixed $value
     *
     * @throws ReflectionException
     */
    protected static function setValue(object $object, string $name, $value): void {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);

        $property->setValue($object, $value);
    }
}
