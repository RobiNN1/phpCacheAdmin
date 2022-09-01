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

class TestCase extends \PHPUnit\Framework\TestCase {
    /**
     * Call private method.
     *
     * @param object $object
     * @param string $name
     * @param mixed  ...$args
     *
     * @return mixed|string
     */
    protected static function callMethod(object $object, string $name, ...$args) {
        try {
            $method = new ReflectionMethod($object, $name);
            $method->setAccessible(true);

            return $method->invokeArgs($object, $args);
        } catch (ReflectionException $e) {
            return $e->getMessage();
        }
    }
}
