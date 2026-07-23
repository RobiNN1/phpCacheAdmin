<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use RobiNN\Pca\Format;
use Throwable;

trait RedisVectorSet {
    /**
     * @return array<string, mixed>
     */
    public function vectorSetInfo(string $key): array {
        try {
            $info = $this->redis->vectorInfo($key);
        } catch (Throwable) {
            return [];
        }

        if ($info === []) {
            return [];
        }

        $size = (int) ($info['size'] ?? 0);

        return [
            'dimension'  => (int) ($info['vector-dim'] ?? 0),
            'size'       => $size,
            'quant_type' => (string) ($info['quant-type'] ?? 'unknown'),
            'max_level'  => (int) ($info['max-level'] ?? 0),
            'hnsw_m'     => (int) ($info['hnsw-m'] ?? 0),
            'attributes' => (int) ($info['attributes-count'] ?? 0),
            'projection' => (int) ($info['projection-input-dim'] ?? 0),
            'truncated'  => $size > $this->max_vector_members ? $this->max_vector_members : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function vectorSetPanel(string $key): array {
        $info = $this->vectorSetInfo($key);

        return $info === [] ? [] : $info + ['tiles' => $this->vectorSetTiles($info)];
    }

    /**
     * @param array<string, mixed> $info
     *
     * @return array<int, array<string, string>>
     */
    public function vectorSetTiles(array $info): array {
        $tiles = [
            ['label' => 'Elements', 'value' => Format::number((int) $info['size'])],
            ['label' => 'Dimensions', 'value' => Format::number((int) $info['dimension'])],
            ['label' => 'Quantization', 'value' => (string) $info['quant_type']],
            ['label' => 'HNSW links (M)', 'value' => Format::number((int) $info['hnsw_m'])],
        ];

        if ((int) $info['projection'] > 0) {
            $tiles[] = ['label' => 'Reduced from', 'value' => Format::number((int) $info['projection']).' dims'];
        }

        if ((int) $info['attributes'] > 0) {
            $tiles[] = ['label' => 'With attributes', 'value' => Format::number((int) $info['attributes'])];
        }

        return $tiles;
    }
}
