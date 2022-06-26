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

namespace RobiNN\Pca\Dashboards\Memcached\MemcacheCompatibility;

class Memcached extends \Memcached implements MemcacheInterface {
    /**
     * Check connection.
     *
     * @return bool
     */
    public function isConnected(): bool {
        return $this->getVersion() || $this->getResultCode() === \Memcached::RES_SUCCESS;
    }

    /**
     * Get server statistics.
     *
     * @return array
     */
    public function getServerStats(): array {
        return array_values(@$this->getStats())[0];
    }

    /**
     * Get all keys.
     *
     * @return array
     */
    public function getKeys(): array {
        return (array) $this->getAllKeys();
    }
}
