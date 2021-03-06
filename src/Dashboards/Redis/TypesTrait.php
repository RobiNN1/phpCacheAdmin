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
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait TypesTrait {
    /**
     * Get key's value. (For edit form)
     *
     * @param Redis  $redis
     * @param string $type
     * @param string $key
     *
     * @return array<mixed, mixed>
     */
    private function getKeyValue(Redis $redis, string $type, string $key): array {
        $value = '';
        $index = null;
        $score = 0;
        $hash_key = '';

        switch ($type) {
            case 'string':
                $value = $redis->get($key);
                break;
            case 'set':
                $members = $redis->sMembers($key);
                $member = Http::get('member', 'int');
                $value = $members[!empty($member) ? $member : 0];
                break;
            case 'list':
                $index = Http::get('index', 'int');
                $value = $redis->lIndex($key, !empty($index) ? $index : 0);
                break;
            case 'zset':
                $ranges = $redis->zRange($key, 0, -1);
                $range = Http::get('range', 'int');
                $range = !empty($range) ? $range : 0;

                $value = $ranges[$range];
                $score = $redis->zScore($key, $value);
                break;
            case 'hash':
                $keys = [];

                foreach ($redis->hGetAll($key) as $k => $hash_Key_value) {
                    $keys[] = $k;
                }

                $hash_key = Http::get('hash_key');
                $hash_key = !empty($hash_key) ? $hash_key : $keys[0];

                $value = $redis->hGet($key, $hash_key);
                break;
            default:
        }

        return [$value, $index, $score, $hash_key];
    }

    /**
     * Get all key's values. (For view key page)
     *
     * @param Redis  $redis
     * @param string $type
     * @param string $key
     *
     * @return array<mixed, mixed>|string
     */
    private function getAllKeyValues(Redis $redis, string $type, string $key) {
        switch ($type) {
            case 'string':
                $value = $redis->get($key);
                break;
            case 'set':
                $value = $redis->sMembers($key);
                break;
            case 'list':
                $value = $redis->lRange($key, 0, -1);
                break;
            case 'zset':
                $value = $redis->zRange($key, 0, -1);
                break;
            case 'hash':
                $value = $redis->hGetAll($key);
                break;
            default:
                $value = $redis->get($key);
        }

        return $value;
    }

    /**
     * Save key.
     *
     * @param Redis $redis
     *
     * @return void
     */
    private function saveKey(Redis $redis): void {
        if (isset($_POST['submit'])) {
            $error = '';
            $type = Http::post('redis_type');
            $key = Http::post('key');
            $value = Http::post('value');
            $expire = Http::post('expire', 'int');
            $old_value = Http::post('old_value');
            $encoder = Http::post('encoder');

            if ($encoder !== 'none') {
                $value = Helpers::encodeValue($value, $encoder);
            }

            switch ($type) {
                case 'string':
                    $redis->set($key, $value);
                    break;
                case 'set':
                    if (Http::post('value') !== $old_value) {
                        $redis->sRem($key, $old_value);
                        $redis->sAdd($key, $value);
                    }
                    break;
                case 'list':
                    $size = $redis->lLen($key);
                    $index = $_POST['index'] ?? '';

                    if ($index === '' || $index === $size) {
                        $redis->rPush($key, $value);
                    } elseif ($index === -1) {
                        $redis->lPush($key, $value);
                    } elseif ($index >= 0 && $index < $size) {
                        $redis->lSet($key, (int) $index, $value);
                    } else {
                        $error = 'Out of bounds index.';
                    }
                    break;
                case 'zset':
                    $redis->zRem($key, $old_value);
                    $redis->zAdd($key, Http::post('score', 'int'), $value);
                    break;
                case 'hash':
                    if ($redis->hExists($key, Http::get('hash_key'))) {
                        $redis->hDel($key, Http::get('hash_key'));
                    }

                    $redis->hSet($key, Http::post('hash_key'), $value);
                    break;
                default:
            }

            if ($expire === -1) {
                $redis->persist($key);
            } else {
                $redis->expire($key, $expire);
            }

            $old_key = Http::post('old_key');

            if ($old_key !== $key) {
                $redis->rename($old_key, $key);
            }

            if (!empty($error)) {
                Helpers::alert($this->template, $error, 'bg-red-500');
            } else {
                Http::redirect(['db'], ['view' => 'key', 'key' => $key]);
            }
        }
    }

    /**
     * Delete sub key.
     *
     * @param Redis  $redis
     * @param string $type
     * @param string $key
     *
     * @return void
     */
    private function deleteSubKey(Redis $redis, string $type, string $key): void {
        switch ($type) {
            case 'set':
                $members = $redis->sMembers($key);
                $redis->sRem($key, $members[Http::get('member', 'int')]);
                break;
            case 'list':
                $redis->lRem($key, $redis->lIndex($key, Http::get('index', 'int')), -1);
                break;
            case 'zset':
                $ranges = $redis->zRange($key, 0, -1);
                $redis->zRem($key, $ranges[Http::get('range', 'int')]);
                break;
            case 'hash':
                $redis->hDel($key, Http::get('hash_key'));
                break;
            default:
        }

        Http::redirect(['db', 'key', 'view', 'p']);
    }

    /**
     * Get a number of items in a key.
     *
     * @param Redis  $redis
     * @param string $type
     * @param string $key
     *
     * @return int|null
     */
    private function getCountOfItemsInKey(Redis $redis, string $type, string $key): ?int {
        switch ($type) {
            case 'set':
                $items = $redis->sCard($key);
                break;
            case 'list':
                $items = $redis->lLen($key);
                break;
            case 'zset':
                $items = $redis->zCard($key);
                break;
            case 'hash':
                $items = $redis->hLen($key);
                break;
            default:
                $items = null;
        }

        return $items;
    }
}
