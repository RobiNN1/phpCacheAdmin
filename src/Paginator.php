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

namespace RobiNN\Pca;

class Paginator {
    /**
     * Paginate array.
     *
     * @param array $keys
     * @param bool  $sort
     * @param int   $default_per_page
     *
     * @return array
     */
    public static function paginate(array &$keys, bool $sort = true, int $default_per_page = 15): array {
        $page = (int) Http::get('p', 'int');
        $page = !empty($page) ? $page : 1;

        $per_page = (int) Http::get('pp', 'int');
        $per_page = !empty($per_page) ? $per_page : $default_per_page;

        if ($sort) {
            usort($keys, static fn ($a, $b) => strcmp((string) $a['key'], (string) $b['key']));
        }

        $keys = array_chunk($keys, $per_page, true);
        array_unshift($keys, '');
        unset($keys[0]);

        $pages = self::limitPagination(count($keys), $page);

        $first_page = !empty($keys[1]) ? $keys[1] : [];
        $keys = !empty($keys[$page]) ? $keys[$page] : $first_page;

        return [$pages, $page, $per_page];
    }

    /**
     * Limit pages in pagination.
     *
     * @param int $keys_count
     * @param int $page
     *
     * @return array
     */
    private static function limitPagination(int $keys_count, int $page): array {
        static $pages = [];

        if ($keys_count >= 5) {
            // it needs more improvements ...

            $limit = 5;

            if ($page > ($limit / 2)) {
                $pages = [1, '...'];
            }

            $counter = 1;

            for ($x = $page, $xMax = $keys_count; $x <= $xMax; $x++) {
                if ($counter < $limit) {
                    $pages[] = $x;
                }
                $counter++;
            }

            if ($page < $keys_count - ($limit / 2)) {
                $pages += ['...', $keys_count];
            }
        } else {
            for ($i = 1, $max = $keys_count; $i <= $max; $i++) {
                $pages[] = $i;
            }
        }

        return $pages;
    }
}
