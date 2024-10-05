<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait RedisTypes {
    /**
     * Get all data types.
     *
     * Used in form.
     *
     * @return array<string, string>
     */
    public function getAllTypes(): array {
        static $types = [];

        $exclude = ['none', 'other', 'stream'];

        if (!$this->redis->checkModule('ReJSON')) {
            $exclude[] = 'rejson';
        }

        foreach ($this->redis->data_types as $type) {
            if (!in_array($type, $exclude, true)) {
                $types[$type] = ucfirst($type);
            }
        }

        return $types;
    }

    /**
     * Extra data for templates.
     *
     * Used in 'view_key_array' template.
     *
     * @return array<string, array<string, array<int, string>|string>>
     */
    private function typesTplOptions(): array {
        return [
            'extra'  => [
                'hide_title' => ['set'],
                'hide_edit'  => ['stream'],
            ],
            'set'    => ['param' => 'member', 'title' => ''],
            'list'   => ['param' => 'index', 'title' => 'Index'],
            'zset'   => ['param' => 'range', 'title' => 'Score'],
            'hash'   => ['param' => 'hash_key', 'title' => 'Field'],
            'stream' => ['param' => 'stream_id', 'title' => 'Entry ID'],
        ];
    }

    /**
     * Get key's value.
     *
     * Used in edit form.
     *
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    private function getKeyValue(string $type, string $key): array {
        $index = null;
        $score = 0;
        $hash_key = '';

        switch ($type) {
            case 'string':
                $value = $this->redis->get($key);
                break;
            case 'set':
                $members = $this->redis->sMembers($key);
                $value = $members[Http::get('member', 0)];
                break;
            case 'list':
                $index = Http::get('index', 0);
                $value = $this->redis->lIndex($key, $index);
                break;
            case 'zset':
                $ranges = $this->redis->zRange($key, 0, -1);
                $range = Http::get('range', 0);

                $value = $ranges[$range];
                $score = $this->redis->zScore($key, $value);
                break;
            case 'hash':
                $keys = array_keys($this->redis->hGetAll($key));
                $hash_key = (string) Http::get('hash_key', $keys[0]);
                $value = $this->redis->hGet($key, $hash_key);
                break;
            case 'stream':
                $ranges = $this->redis->xRange($key, '-', '+');
                $value = $ranges[Http::get('stream_id', '')];
                break;
            case 'rejson':
                $value = $this->redis->jsonGet($key);
                break;
            default:
                $value = '';
        }

        return [$value, $index, $score, $hash_key];
    }

    /**
     * Get all key values.
     *
     * Used in view page.
     *
     * @return array<int, mixed>|string
     *
     * @throws Exception
     */
    public function getAllKeyValues(string $type, string $key): array|string {
        return match ($type) {
            'string' => $this->redis->get($key),
            'set' => $this->redis->sMembers($key),
            'list' => $this->redis->lRange($key, 0, -1),
            'zset' => $this->redis->zRange($key, 0, -1),
            'hash' => $this->redis->hGetAll($key),
            'stream' => $this->redis->xRange($key, '-', '+'),
            'rejson' => $this->redis->jsonGet($key),
            default => '',
        };
    }

    /**
     * Save key with correct function.
     *
     * Used in saveKey().
     *
     * @param array<string, mixed> $options
     *
     * @throws Exception
     */
    public function store(string $type, string $key, string $value, string $old_value = '', array $options = []): void {
        switch ($type) {
            case 'string':
                $this->redis->set($key, $value);
                break;
            case 'set':
                if ($value !== $old_value) {
                    $this->redis->sRem($key, $old_value);
                    $this->redis->sAdd($key, $value);
                }

                break;
            case 'list':
                $size = $this->redis->lLen($key);
                $index = $options['list_index'] ?? '';

                if ($index === '' || $index === (string) $size) { // append
                    $this->redis->rPush($key, $value);
                } elseif ($index === '-1') { // prepend
                    $this->redis->lPush($key, $value);
                } elseif ($index >= 0 && $index < $size) {
                    $this->redis->lSet($key, (int) $index, $value);
                } else {
                    Http::stopRedirect();
                    Helpers::alert($this->template, 'Out of bounds index.', 'error');
                }

                break;
            case 'zset':
                $this->redis->zRem($key, $old_value);
                $this->redis->zAdd($key, $options['zset_score'], $value);
                break;
            case 'hash':
                if ($this->redis->hExists($key, Http::get('hash_key', ''))) {
                    $this->redis->hDel($key, Http::get('hash_key', ''));
                }

                $this->redis->hSet($key, $options['hash_key'], $value);
                break;
            case 'stream':
                $this->redis->streamAdd($key, $options['stream_id'], $options['stream_fields'] ?? [$options['stream_field'] => $value]);
                break;
            case 'rejson':
                $this->redis->jsonSet($key, $value);
                break;
            default:
        }
    }

    /**
     * @throws Exception
     */
    public function deleteSubKey(string $type, string $key, int|string|null $subkey = null): void {
        switch ($type) {
            case 'set':
                $members = $this->redis->sMembers($key);
                $this->redis->sRem($key, $members[$subkey]);
                break;
            case 'list':
                $value = $this->redis->lIndex($key, $subkey);
                $this->redis->listRem($key, ($value !== false ? $value : ''), -1);
                break;
            case 'zset':
                $ranges = $this->redis->zRange($key, 0, -1);
                $this->redis->zRem($key, $ranges[$subkey]);
                break;
            case 'hash':
                $this->redis->hDel($key, $subkey);
                break;
            case 'stream':
                $this->redis->xDel($key, [$subkey]);
                break;
            default:
        }
    }

    /**
     * @throws Exception
     */
    private function getCountOfItemsInKey(string $type, string $key): ?int {
        return match ($type) {
            'set' => $this->redis->sCard($key),
            'list' => $this->redis->lLen($key),
            'zset' => $this->redis->zCard($key),
            'hash' => $this->redis->hLen($key),
            'stream' => $this->redis->xLen($key),
            default => null,
        };
    }
}
