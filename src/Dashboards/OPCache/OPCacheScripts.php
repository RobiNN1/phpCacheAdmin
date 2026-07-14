<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait OPCacheScripts {
    private function ignorePcaScripts(): bool {
        return isset($_GET['ignore']) && $_GET['ignore'] === 'yes';
    }

    private function isPcaScript(string $full_path): bool {
        $pca_root = ($_SERVER['DOCUMENT_ROOT'] ?? '').str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? '');

        return str_starts_with(strtr($full_path, ['phar://' => '']), $pca_root);
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function getCachedScripts(): array {
        $cached_scripts = [];
        $search = Http::get('s', '');

        $this->template->addGlobal('search_value', $search);

        $status = opcache_get_status();

        if (isset($status['scripts'])) {
            $ignore_pca = $this->ignorePcaScripts();

            foreach ($status['scripts'] as $script) {
                $full_path = str_replace('\\', '/', $script['full_path']);

                if ($ignore_pca && $this->isPcaScript($full_path)) {
                    continue;
                }

                if ($search === '' || stripos($script['full_path'], $search) !== false) {
                    $cached_scripts[] = [
                        'key'  => $script['full_path'],
                        'info' => [
                            'title'              => $full_path,
                            'number_hits'        => $script['hits'],
                            'bytes_memory'       => $script['memory_consumption'],
                            'timediff_last_used' => $script['last_used_timestamp'],
                            'time_created'       => $script['timestamp'] ?? 0,
                        ],
                    ];
                }
            }
        }

        unset($status);

        return Helpers::sortKeys($cached_scripts);
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptsTab(): array {
        $cached_scripts = $this->getCachedScripts();
        $paginator = new Paginator($cached_scripts, [['ignore', 'pp', 's'], ['p' => '']]);
        $status = opcache_get_status(false);

        return [
            'cached_scripts' => $paginator->getPaginated(),
            'all_keys'       => $status !== false ? $status['opcache_statistics']['num_cached_scripts'] : 0,
            'paginator'      => $paginator->render(),
            'is_ignored'     => $this->ignorePcaScripts(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getScriptsMap(): array {
        $status = opcache_get_status();
        $ignore_pca = $this->ignorePcaScripts();
        $tree = [];

        foreach ($status['scripts'] ?? [] as $script) {
            $full_path = str_replace(['\\', 'phar://'], ['/', ''], $script['full_path']);

            if ($ignore_pca && $this->isPcaScript($full_path)) {
                continue;
            }

            $parts = array_values(array_filter(explode('/', $full_path), static fn (string $part): bool => $part !== ''));

            if ($parts === []) {
                continue;
            }

            $node = &$tree;
            $last = array_key_last($parts);

            foreach ($parts as $i => $part) {
                if ($i === $last) {
                    $node[$part] = $script['memory_consumption'];
                } else {
                    if (!isset($node[$part]) || !is_array($node[$part])) {
                        $node[$part] = [];
                    }

                    $node = &$node[$part];
                }
            }

            unset($node);
        }

        return $this->buildTreemapNodes($tree);
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTreemapNodes(array $tree): array {
        $nodes = [];

        foreach ($tree as $name => $value) {
            if (is_array($value)) {
                $children = $this->buildTreemapNodes($value);

                while (count($children) === 1 && isset($children[0]['children'])) {
                    $name .= '/'.$children[0]['name'];
                    $children = $children[0]['children'];
                }

                $nodes[] = ['name' => $name, 'children' => $children];
            } else {
                $nodes[] = ['name' => $name, 'value' => $value];
            }
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function treemapTab(): array {
        $status = opcache_get_status(false);

        if ($status === false) {
            return ['tab_error' => 'OPcache is not available, it is either disabled (opcache.enable) or restricted (opcache.restrict_api).'];
        }

        return [
            'treemap'    => $this->getScriptsMap(),
            'is_ignored' => $this->ignorePcaScripts(),
        ];
    }
}
