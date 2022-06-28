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
use RobiNN\Pca\Admin;
use RobiNN\Pca\Helpers;

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
            $type = Admin::post('redis_type');
            $key = Admin::post('key');
            $value = Admin::post('value');
            $expire = Admin::post('expire', 'int');

            switch ($type) {
                case 'string':
                    $connect->set($key, $value);
                    break;
                case 'set':
                    if (Admin::post('value') !== Admin::post('old_value')) {
                        $connect->sRem($key, Admin::post('old_value'));
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
                    $connect->zRem($key, Admin::post('old_value'));
                    $connect->zAdd($key, Admin::post('score', 'int'), $value);
                    break;
                case 'hash':
                    if (isset($_GET['key']) && !$connect->hExists($key, Admin::post('hash_key'))) {
                        $connect->hDel($key, Admin::post('hash_key'));
                    }

                    $connect->hSet($key, Admin::post('hash_key'), $value);
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
                Admin::redirect(Admin::queryString(['db', 'key'], ['view' => 'key']));
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
                $connect->sRem($key, $members[Admin::get('member', 'int')]);
                break;
            case 'list':
                $connect->lRem($key, $connect->lIndex($key, Admin::get('index', 'int')), -1);
                break;
            case 'zset':
                $ranges = $connect->zRange($key, 0, -1);
                $connect->zRem($key, $ranges[Admin::get('range', 'int')]);
                break;
            case 'hash':
                $connect->hDel($key, Admin::get('hash_key'));
                break;
            default:
        }

        if (!empty($error)) {
            Helpers::alert($this->template, $error, 'bg-red-500');
        } else {
            Admin::redirect(Admin::queryString(['db', 'key', 'view', 'p']));
        }
    }
}
