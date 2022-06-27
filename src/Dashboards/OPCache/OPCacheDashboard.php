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

use RobiNN\Pca\Admin;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Template;

class OPCacheDashboard implements DashboardInterface {
    use OPCacheTrait;

    private Template $template;

    public function __construct(Template $template) {
        $this->template = $template;
    }

    /**
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string {
        $return = '';

        if (isset($_GET['deleteall']) && opcache_reset() === true) {
            $return = $this->template->render('components/alert', [
                'message' => 'Cache has been cleaned.',
            ]);
        }

        if (isset($_GET['delete'])) {
            $file = base64_decode(Admin::get('delete'));

            if (opcache_invalidate($file, true)) {
                $name = explode(DIRECTORY_SEPARATOR, $file);

                $return = $this->template->render('components/alert', [
                    'message' => sprintf('File "%s" was invalidated.', $name[array_key_last($name)]),
                ]);
            }
        }

        return $return;
    }

    /**
     * Data for info panels.
     *
     * @return array
     */
    public function info(): array {
        $status = opcache_get_status();
        $stats = $status['opcache_statistics'];
        $memory = $status['memory_usage'];

        return [
            'panels' => [
                [
                    'title'    => 'Status',
                    'moreinfo' => true,
                    'data'     => [
                        'JIT'          => Admin::enabledDisabledBadge($this->template, isset($status['jit']) && $status['jit']['enabled']),
                        'Start time'   => date(Admin::getConfig('time_format'), $stats['start_time']),
                        'Last restart' => $stats['last_restart_time'] === 0 ? 'Never' : date(Admin::getConfig('time_format'), $stats['last_restart_time']),
                        'Cache full'   => Admin::enabledDisabledBadge($this->template, $status['cache_full'] === false, null, ['No', 'Yes']),
                    ],
                ],
                [
                    'title' => 'Memory',
                    'data'  => [
                        'Used'           => Admin::formatSize($memory['used_memory']),
                        'Free'           => Admin::formatSize($memory['free_memory']),
                        'Wasted'         => Admin::formatSize($memory['wasted_memory']),
                        'Current wasted' => round($memory['current_wasted_percentage'], 5).'%',
                    ],
                ],
                [
                    'title' => 'Stats',
                    'data'  => [
                        'Cached scripts' => $stats['num_cached_scripts'],
                        'Cached keys'    => $stats['num_cached_keys'],
                        'Hits'           => $stats['hits'],
                        'Misses'         => $stats['misses'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        $status = opcache_get_status();

        if (isset($_GET['moreinfo'])) {
            $return = $this->moreInfo($status);
        } else {
            $cached_scripts = [];
            foreach ($status['scripts'] as $script) {
                $name = explode(DIRECTORY_SEPARATOR, $script['full_path']);

                $cached_scripts[] = [
                    'path'           => $script['full_path'],
                    'name'           => $name[array_key_last($name)],
                    'hits'           => $script['hits'],
                    'memory'         => Admin::formatSize($script['memory_consumption']),
                    'last_used'      => date(Admin::getConfig('time_format'), $script['last_used_timestamp']),
                    'created'        => date(Admin::getConfig('time_format'), $script['timestamp']),
                    'invalidate_url' => base64_encode($script['full_path']),
                ];
            }

            [$pages, $page, $per_page] = Admin::paginate($cached_scripts, false, 50);

            $return = $this->template->render('dashboards/opcache', [
                'show_info'         => !isset($_GET['moreinfo']),
                'title'             => 'OPCache',
                'extension_version' => phpversion('Zend OPcache'),
                'info'              => $this->info(),
                'cached_scripts'    => $cached_scripts,
                'current_page'      => $page,
                'paginate'          => $pages,
                'paginate_url'      => Admin::queryString(['pp'], ['p' => '']),
                'per_page'          => $per_page,
            ]);
        }

        return $return;
    }
}
