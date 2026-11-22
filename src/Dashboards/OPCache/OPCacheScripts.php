<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Csrf;
use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\KeyFilters;
use RobiNN\Pca\Paginator;

trait OPCacheScripts {
    private function ignorePcaScripts(): bool {
        // The paths of this tool only mean something on this server.
        return !$this->isRemote() && isset($_GET['ignore']) && $_GET['ignore'] === 'yes';
    }

    private function isPcaScript(string $full_path): bool {
        $pca_root = ($_SERVER['DOCUMENT_ROOT'] ?? '').str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? '');

        return str_starts_with(strtr($full_path, ['phar://' => '']), $pca_root);
    }

    private ?string $filter_error = null;

    /**
     * The search and filters from the URL.
     */
    public function keyFilters(): KeyFilters {
        return new KeyFilters(['nf', 'idle', 'minsize', 'maxsize']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCachedScripts(): array {
        $cached_scripts = [];
        $filters = $this->keyFilters();
        $this->template->addGlobal('search_value', $filters->values['s']);

        $matcher = $filters->matcher();
        $this->filter_error = $filters->error;
        $with_filters = $filters->active();
        $now = time();

        $status = $this->status(true);

        if (isset($status['scripts'])) {
            $ignore_pca = $this->ignorePcaScripts();

            foreach ($status['scripts'] as $script) {
                $full_path = str_replace('\\', '/', $script['full_path']);

                if ($ignore_pca && $this->isPcaScript($full_path)) {
                    continue;
                }

                if ($matcher !== null && !$matcher($script['full_path'])) {
                    continue;
                }

                if ($with_filters && !$filters->matches([
                        'idle' => $now - (int) $script['last_used_timestamp'],
                        'size' => (int) $script['memory_consumption'],
                        'read' => (int) $script['hits'] > 0,
                    ])) {
                    continue;
                }

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

        unset($status);

        return Helpers::sortKeys($cached_scripts);
    }

    /**
     * Invalidate every script that matches the search and filters.
     */
    private function invalidateMatchingScripts(): string {
        if (!$this->keyFilters()->narrowed()) {
            return Helpers::alert('Set a search or a filter first, use "Delete all" to reset the whole cache.', 'error');
        }

        $scripts = $this->getCachedScripts();

        if ($this->filter_error !== null) {
            return Helpers::alert($this->filter_error, 'error');
        }

        $invalidated = 0;

        foreach ($scripts as $script) {
            $invalidated += opcache_invalidate((string) $script['key'], true) ? 1 : 0;
        }

        return Helpers::alert(sprintf('%s matching %s been invalidated.', Format::number($invalidated), $invalidated === 1 ? 'script has' : 'scripts have'), 'success');
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptsTab(): array {
        $status = $this->status();

        if ($status === false) {
            return ['tab_error' => self::NOT_AVAILABLE];
        }

        if (isset($_POST['delete_matching']) && !$this->isRemote()) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                $this->invalidateMatchingScripts();
            } else {
                Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        $cached_scripts = $this->getCachedScripts();

        if ($this->filter_error !== null) {
            Helpers::alert($this->filter_error, 'error');
        }

        $filters = $this->keyFilters();
        $paginator = new Paginator($cached_scripts, [['ignore', 'pp', ...$filters->params()], ['p' => '']]);

        return [
            'cached_scripts' => $paginator->getPaginated(),
            'all_keys'       => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
            'paginator'      => $paginator->render(),
            'is_ignored'     => $this->ignorePcaScripts(),
            'is_remote'      => $this->isRemote(),
            'key_filters'    => $filters->view(
                count($cached_scripts),
                ['nf' => 'Never hit', 'minsize' => 'Memory (bytes)'],
                $this->isRemote() ? null : 'Invalidate',
                noun: 'script'
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getScriptsMap(): array {
        $status = $this->status(true);
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
        $status = $this->status();

        if ($status === false) {
            return ['tab_error' => self::NOT_AVAILABLE];
        }

        return [
            'treemap'    => $this->getScriptsMap(),
            'is_ignored' => $this->ignorePcaScripts(),
            'is_remote'  => $this->isRemote(),
        ];
    }
}
