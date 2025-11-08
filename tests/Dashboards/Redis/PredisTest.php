<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\Redis;

use Predis\Client;

final class PredisTest extends RedisTestCase {
    protected string $client = 'predis';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!class_exists(Client::class)) {
            self::markTestSkipped('Predis is not installed. Skipping tests.');
        }
    }
}
