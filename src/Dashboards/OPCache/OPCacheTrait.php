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

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Config;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait OPCacheTrait {
    /**
     * Delete script.
     *
     * @return string
     */
    private function deleteScript(): string {
        $file = base64_decode(Http::get('delete'));

        if (opcache_invalidate($file, true)) {
            $name = explode(DIRECTORY_SEPARATOR, $file);

            $message = sprintf('File "%s" was invalidated.', $name[array_key_last($name)]);
        } else {
            $message = 'An error occurred while invalidating the script.';
        }

        return $this->template->render('components/alert', ['message' => $message]);
    }

    /**
     * Show more info.
     *
     * @param array<string, mixed> $status
     *
     * @return string
     */
    private function moreInfo(array $status): string {
        unset($status['scripts']);

        return $this->template->render('partials/info_table', [
            'panel_title' => 'OPCache Info',
            'array'       => Helpers::convertBoolToString($status),
        ]);
    }

    /**
     * Get cached scripts.
     *
     * @param array<string, mixed> $status
     *
     * @return array<int, array<string, string|int>>
     */
    private function getCachedScripts(array $status): array {
        static $cached_scripts = [];

        if (isset($status['scripts'])) {
            foreach ($status['scripts'] as $script) {
                $name = explode('/', str_replace('\\', '/', $script['full_path']));

                $cached_scripts[] = [
                    'path'           => $script['full_path'],
                    'name'           => $name[array_key_last($name)],
                    'hits'           => $script['hits'],
                    'memory'         => Helpers::formatBytes($script['memory_consumption']),
                    'last_used'      => date(Config::get('timeformat'), $script['last_used_timestamp']),
                    'created'        => date(Config::get('timeformat'), $script['timestamp']),
                    'invalidate_url' => base64_encode($script['full_path']),
                ];
            }
        }

        return $cached_scripts;
    }

    /**
     * Main dashboard content.
     *
     * @param array<string, mixed> $status
     *
     * @return string
     */
    private function mainDashboard(array $status): string {
        $cached_scripts = $this->getCachedScripts($status);

        $paginator = new Paginator($this->template, $cached_scripts, 50);
        $paginator->setSelect([50, 100, 200, 300, 400]);

        return $this->template->render('dashboards/opcache', [
            'cached_scripts' => $paginator->getPaginated(),
            'paginator'      => $paginator->render(),
        ]);
    }
}
