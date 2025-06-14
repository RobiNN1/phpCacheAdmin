<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Exception;

trait RedisJson {
    /**
     * @throws Exception
     */
    public function jsonGet(string $key): string {
        return $this->rawCommand('JSON.GET', $key);
    }

    /**
     * @throws Exception
     */
    public function jsonSet(string $key, mixed $value): bool {
        $raw = $this->rawCommand('JSON.SET', $key, '$', $value);

        return $raw === true || $raw === 'OK';
    }
}
