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

        $directives = opcache_get_configuration()['directives'];

        $status['directives'] = array_combine(
            array_map(static fn ($key) => str_replace('opcache.', '', $key), array_keys($directives)),
            $directives
        );

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
                $full_path = str_replace('\\', '/', $script['full_path']);
                $name = explode('/', $full_path);
                $script_name = $name[array_key_last($name)];

                if ((isset($_GET['ignore']) && $_GET['ignore'] === 'yes') && Helpers::str_starts_with($full_path, $_SERVER['DOCUMENT_ROOT'])) {
                    continue;
                }

                $cached_scripts[] = [
                    'path'           => $full_path,
                    'name'           => $script_name,
                    'hits'           => Helpers::formatNumber($script['hits']),
                    'memory'         => Helpers::formatBytes($script['memory_consumption']),
                    'last_used'      => Helpers::formatTime($script['last_used_timestamp']),
                    'created'        => Helpers::formatTime($script['timestamp']),
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

        $paginator = new Paginator($this->template, $cached_scripts, [['ignore', 'pp'], ['p' => '']]);

        $is_ignored = isset($_GET['ignore']) && $_GET['ignore'] === 'yes';

        return $this->template->render('dashboards/opcache', [
            'cached_scripts' => $paginator->getPaginated(),
            'paginator'      => $paginator->render(),
            'ignore_url'     => Http::queryString(['pp', 'p'], ['ignore' => $is_ignored ? 'no' : 'yes']),
            'is_ignored'     => $is_ignored,
        ]);
    }
}
