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

namespace RobiNN\Pca\Dashboards\Server;

use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Template;

class ServerDashboard implements DashboardInterface {
    use ServerTrait;

    private Template $template;

    public function __construct(Template $template) {
        $this->template = $template;
    }

    /**
     * Check if extension is installed.
     *
     * @return bool
     */
    public function check(): bool {
        return true;
    }

    /**
     * Get dashboard info.
     *
     * @return array<string, string>
     */
    public function getDashboardInfo(): array {
        return [
            'key'   => 'server',
            'title' => 'Server',
            'color' => 'slate',
        ];
    }

    /**
     * Ajax content.
     *
     * @return string
     */
    public function ajax(): string {
        return '';
    }

    /**
     * Data for info panels.
     *
     * @return array<string, mixed>
     */
    public function info(): array {
        $xdebug = Helpers::enabledDisabledBadge(
            $this->template,
            extension_loaded('xdebug'),
            ' - v'.phpversion('xdebug')
        );

        return [
            'panels' => [
                [
                    'title'    => 'PHP Info',
                    'moreinfo' => true,
                    'data'     => [
                        'PHP Version'          => PHP_VERSION,
                        'PHP Interface'        => PHP_SAPI,
                        'Max Upload File Size' => ini_get('file_uploads') ? ini_get('upload_max_filesize').'B' : 'n/a',
                        'Disabled functions'   => $this->getDisabledFunctions(),
                        'Xdebug'               => $xdebug,
                    ],
                ],
                [
                    'title' => 'Server Info',
                    'data'  => [
                        'Server'     => php_uname(),
                        'Web Server' => $_SERVER['SERVER_SOFTWARE'],
                        'User Agent' => $_SERVER['HTTP_USER_AGENT'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Show info panels.
     *
     * @return string
     */
    public function showPanels(): string {
        if (isset($_GET['moreinfo'])) {
            return '';
        }

        return $this->template->render('partials/info', [
            'panels_toggler' => false,
            'info'           => $this->info(),
        ]);
    }

    /**
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        if (isset($_GET['moreinfo'])) {
            return $this->phpinfo();
        }

        return $this->template->render('dashboards/server', [
            'extensions' => get_loaded_extensions(),
        ]);
    }
}
