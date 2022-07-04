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

class Memcache extends \Memcache implements MemcacheInterface {
    private array $server;

    public function __construct(array $server = []) {
        $this->server = $server;
    }

    /**
     * Check connection.
     *
     * @return bool
     */
    public function isConnected(): bool {
        return $this->getServerStatus($this->server['host'], (int) $this->server['port']) !== 0;
    }

    /**
     * Get server statistics.
     *
     * @return array
     */
    public function getServerStats(): array {
        return (array) @$this->getStats();
    }

    /**
     * Get all keys.
     *
     * @return array
     */
    public function getKeys(): array {
        $list = [];

        foreach (@$this->getExtendedStats('slabs') as $slabs) {
            $slabs = (array) $slabs;
            unset($slabs['active_slabs'], $slabs['total_malloced']);

            foreach (array_keys($slabs) as $slab_id) {
                foreach ($this->getExtendedStats('cachedump', (int) $slab_id) as $entries) {
                    if (!empty($entries)) {
                        foreach ($entries as $name => $data) {
                            $list[] = $name;
                        }
                    }
                }
            }
        }

        return $list;
    }
}
