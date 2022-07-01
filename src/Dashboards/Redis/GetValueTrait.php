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

namespace RobiNN\Pca\Dashboards\Redis;

use Redis;
use RobiNN\Pca\Http;

trait GetValueTrait {
    /**
     * Get key's value. (For edit form)
     *
     * @param Redis  $connect
     * @param string $type
     * @param string $key
     *
     * @return array
     */
    private function getKeyValue(Redis $connect, string $type, string $key): array {
        $value = '';
        $index = null;
        $score = 0;
        $hash_key = '';

        switch ($type) {
            case 'string':
                $value = $connect->get($key);
                break;
            case 'set':
                $members = $connect->sMembers($key);
                $member = Http::get('member', 'int');
                $value = $members[!empty($member) ? $member : 0];
                break;
            case 'list':
                $index = Http::get('index', 'int');
                $value = $connect->lIndex($key, !empty($index) ? $index : 0);
                break;
            case 'zset':
                $ranges = $connect->zRange($key, 0, -1);
                $range = Http::get('range', 'int');
                $range = !empty($range) ? $range : 0;

                $value = $ranges[$range];
                $score = $connect->zScore($key, $value);
                break;
            case 'hash':
                $keys = [];

                foreach ($connect->hGetAll($key) as $k => $hash_Key_value) {
                    $keys[] = $k;
                }

                $hash_key = Http::get('hash_key');
                $hash_key = !empty($hash_key) ? $hash_key : $keys[0];

                $value = $connect->hGet($key, $hash_key);
                break;
            default:
        }

        return [$value, $index, $score, $hash_key];
    }

    /**
     * Get all key's values. (For vie key)
     *
     * @param Redis  $connect
     * @param string $type
     * @param string $key
     *
     * @return array|string
     */
    private function getAllKeyValues(Redis $connect, string $type, string $key) {
        switch ($type) {
            case 'string':
                $value = $connect->get($key);
                break;
            case 'set':
                $value = $connect->sMembers($key);
                break;
            case 'list':
                $value = $connect->lRange($key, 0, -1);
                break;
            case 'zset':
                $value = $connect->zRange($key, 0, -1);
                break;
            case 'hash':
                $value = $connect->hGetAll($key);
                break;
            default:
                $value = $connect->get($key);
        }

        return $value;
    }
}
