<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis\Compatibility;

use Exception;
use RedisException;

trait RedisModules {
    /**
     * @return array<int, array<string, int|string>>
     *
     * @throws Exception
     */
    public function getModules(): array {
        static $modules = [];

        try {
            $list = $this->rawCommand('MODULE', 'LIST'); // require Redis >= 4.0
        } catch (RedisException) {
            return [];
        }

        if (!is_array($list) || count($list) === 0) {
            return [];
        }

        foreach ($list as $module) {
            $modules[] = [
                $module[0] => $module[1], // name
                $module[2] => $module[3], // version
            ];
        }

        return $modules;
    }

    /**
     * @throws Exception
     */
    public function checkModule(string $module): bool {
        return in_array($module, array_column($this->getModules(), 'name'), true);
    }
}
