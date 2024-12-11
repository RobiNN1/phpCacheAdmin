<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Dashboards\Redis;

use Tests\Dashboards\Redis\RedisTestCase;

class PredisTest extends RedisTestCase {
    protected string $client = 'predis';
}
