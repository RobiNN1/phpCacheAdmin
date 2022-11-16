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
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

class ServerDashboard implements DashboardInterface {
    use ServerTrait;

    private Template $template;

    public function __construct(Template $template) {
        $this->template = $template;
    }

    public static function check(): bool {
        return true; // No extension required, just return true.
    }

    /**
     * @return array<string, string>
     */
    public function dashboardInfo(): array {
        return [
            'key'   => 'server',
            'title' => 'Server',
        ];
    }

    public function ajax(): string {
        return '';
    }

    public function infoPanels(): string {
        // Hide panels on more info page.
        if (isset($_GET['moreinfo'])) {
            return '';
        }

        return $this->template->render('partials/info', [
            'panels_toggler' => false,
            'info'           => [
                'panels' => [
                    [
                        'title'    => 'PHP Info',
                        'moreinfo' => true,
                        'data'     => [
                            'PHP Version'          => PHP_VERSION,
                            'PHP Interface'        => PHP_SAPI,
                            'Max Upload File Size' => ini_get('file_uploads') ? ini_get('upload_max_filesize').'B' : 'n/a',
                            'Disabled functions'   => $this->getDisabledFunctions(),
                            'Xdebug'               => extension_loaded('xdebug') ? 'Enabled - v'.phpversion('xdebug') : 'Disabled',
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
            ],
        ]);
    }

    public function dashboard(): string {
        if (isset($_GET['moreinfo'])) {
            return $this->phpInfo();
        }

        return $this->template->render('dashboards/server', [
            'extensions' => get_loaded_extensions(),
            'ext_link'   => Http::queryString([], ['moreinfo' => 0]),
        ]);
    }
}
