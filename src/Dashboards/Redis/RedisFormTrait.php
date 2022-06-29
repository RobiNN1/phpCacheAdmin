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

trait RedisFormTrait {
    /**
     * Save key.
     *
     * @param Redis $connect
     *
     * @return void
     */
    private function saveKey(Redis $connect): void {
        if (isset($_POST['submit'])) {
            $error = '';
            $type = Http::post('redis_type');
            $key = Http::post('key');
            $value = Http::post('value');
            $expire = Http::post('expire', 'int');

            switch ($type) {
                case 'string':
                    $connect->set($key, $value);
                    break;
                case 'set':
                    if (Http::post('value') !== Http::post('old_value')) {
                        $connect->sRem($key, Http::post('old_value'));
                        $connect->sAdd($key, $value);
                    }
                    break;
                case 'list':
                    $size = $connect->lLen($key);
                    $index = $_POST['index'] ?? '';

                    if ($index === '' || $index === $size) {
                        $connect->rPush($key, $value);
                    } elseif ($index === -1) {
                        $connect->lPush($key, $value);
                    } elseif ($index >= 0 && $index < $size) {
                        $connect->lSet($key, (int) $index, $value);
                    } else {
                        $error = 'Out of bounds index.';
                    }
                    break;
                case 'zset':
                    $connect->zRem($key, Http::post('old_value'));
                    $connect->zAdd($key, Http::post('score', 'int'), $value);
                    break;
                case 'hash':
                    if (isset($_GET['key']) && !$connect->hExists($key, Http::post('hash_key'))) {
                        $connect->hDel($key, Http::post('hash_key'));
                    }

                    $connect->hSet($key, Http::post('hash_key'), $value);
                    break;
                default:
            }

            if ($expire === -1) {
                $connect->persist($key);
            } else {
                $connect->expire($key, $expire);
            }

            if (!empty($error)) {
                Helpers::alert($this->template, $error, 'bg-red-500');
            } else {
                Http::redirect(['db', 'key'], ['view' => 'key']);
            }
        }
    }

    /**
     * Delete sub key.
     *
     * @param Redis  $connect
     * @param string $type
     * @param string $key
     *
     * @return void
     */
    private function deleteSubKey(Redis $connect, string $type, string $key): void {
        $error = '';

        switch ($type) {
            case 'set':
                $members = $connect->sMembers($key);
                $connect->sRem($key, $members[Http::get('member', 'int')]);
                break;
            case 'list':
                $connect->lRem($key, $connect->lIndex($key, Http::get('index', 'int')), -1);
                break;
            case 'zset':
                $ranges = $connect->zRange($key, 0, -1);
                $connect->zRem($key, $ranges[Http::get('range', 'int')]);
                break;
            case 'hash':
                $connect->hDel($key, Http::get('hash_key'));
                break;
            default:
        }

        if (!empty($error)) {
            Helpers::alert($this->template, $error, 'bg-red-500');
        } else {
            Http::redirect(['db', 'key', 'view', 'p']);
        }
    }
}
