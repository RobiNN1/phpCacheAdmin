<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use RedisException;

trait RedisJson {
    /**
     * @throws RedisException
     */
    public function jsonGet(string $key): mixed {
        return $this->rawCommand('JSON.GET', $key);
    }

    /**
     * @throws RedisException
     */
    public function jsonSet(string $key, mixed $value): bool {
        $raw = $this->rawCommand('JSON.SET', $key, '$', $value);

        return $raw === true || $raw === 'OK';
    }
}
