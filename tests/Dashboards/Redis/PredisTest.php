<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use Predis\Client;

class PredisTest extends RedisTestCase {
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!class_exists(Client::class)) {
            self::markTestSkipped('Predis is not installed. Skipping tests.');
        }

        if (self::$is_cluster) {
            self::markTestSkipped('There is currently no support for clusters with Predis. Skipping tests.');
        }
    }

    protected string $client = 'predis';
}
