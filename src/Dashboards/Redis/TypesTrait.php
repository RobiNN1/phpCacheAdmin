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
use RedisException;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Value;

trait TypesTrait {
    /**
     * @var array<int, string>
     */
    private array $data_types = [
        Redis::REDIS_NOT_FOUND => 'other',
        Redis::REDIS_STRING    => 'string',
        Redis::REDIS_SET       => 'set',
        Redis::REDIS_LIST      => 'list',
        Redis::REDIS_ZSET      => 'zset',
        Redis::REDIS_HASH      => 'hash',
    ];

    /**
     * Get all data types.
     *
     * @return array<string, string>
     */
    private function getAllTypes(): array {
        static $types = [];

        unset($this->data_types[Redis::REDIS_NOT_FOUND]);

        foreach ($this->data_types as $type) {
            $types[$type] = ucfirst($type);
        }

        return $types;
    }

    /**
     * Get a key type.
     *
     * @param int $type
     *
     * @return string
     * @throws DashboardException
     */
    private function getType(int $type): string {
        if (!isset($this->data_types[$type])) {
            throw new DashboardException(sprintf('Unsupported data type: %s', $type));
        }

        return $this->data_types[$type];
    }

    /**
     * Get key's value.
     *
     * Used in edit form.
     *
     * @param Redis  $redis
     * @param string $type
     * @param string $key
     *
     * @return array<int, mixed>
     * @throws RedisException
     */
    private function getKeyValue(Redis $redis, string $type, string $key): array {
        $index = null;
        $score = 0;
        $hash_key = '';

        switch ($type) {
            case 'string':
                $value = $redis->get($key);
                break;
            case 'set':
                $members = $redis->sMembers($key);
                $value = $members[Http::get('member', 'int')];
                break;
            case 'list':
                $index = Http::get('index', 'int');
                $value = $redis->lIndex($key, $index);
                break;
            case 'zset':
                $ranges = $redis->zRange($key, 0, -1);
                $range = Http::get('range', 'int');

                $value = $ranges[$range];
                $score = $redis->zScore($key, $value);
                break;
            case 'hash':
                $keys = [];

                foreach ($redis->hGetAll($key) as $k => $hash_Key_value) {
                    $keys[] = $k;
                }

                $hash_key = Http::get('hash_key', 'string', $keys[0]);
                $value = $redis->hGet($key, $hash_key);
                break;
            default:
                $value = '';
        }

        return [$value, $index, $score, $hash_key];
    }

    /**
     * Get all key's values.
     *
     * Used in view page.
     *
     * @param Redis  $redis
     * @param string $type
     * @param string $key
     *
     * @return array<int, mixed>|string
     * @throws RedisException
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
     * @throws RedisException
     */
    private function saveKey(Redis $redis): void {
        $key = Http::post('key');
        $value = Value::encode(Http::post('value'), Http::post('encoder'));
        $old_value = Http::post('old_value');

        switch (Http::post('redis_type')) {
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

                if ($index === '' || $index === (string) $size) {
                    $redis->rPush($key, $value);
                } elseif ($index === '-1') {
                    $redis->lPush($key, $value);
                } elseif ($index >= 0 && $index < $size) {
                    $redis->lSet($key, (int) $index, $value);
                } else {
                    Http::stopRedirect();
                    Helpers::alert($this->template, 'Out of bounds index.', 'bg-red-500');
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

        $expire = Http::post('expire', 'int');

        if ($expire === -1) {
            $redis->persist($key);
        } else {
            $redis->expire($key, $expire);
        }

        $old_key = Http::post('old_key');

        if ($old_key !== $key) {
            $redis->rename($old_key, $key);
        }

        Http::redirect(['db'], ['view' => 'key', 'key' => $key]);
    }

    /**
     * Delete sub key.
     *
     * @param Redis  $redis
     * @param string $type
     * @param string $key
     *
     * @return void
     * @throws RedisException
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
     * @throws RedisException
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
