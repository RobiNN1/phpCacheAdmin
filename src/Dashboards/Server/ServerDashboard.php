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

use RobiNN\Pca\Admin;
use RobiNN\Pca\Dashboards\DashboardInterface;
use RobiNN\Pca\Template;

class ServerDashboard implements DashboardInterface {
    use ServerTrait;

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
        return '';
    }

    /**
     * Data for info panels.
     *
     * @return array
     */
    public function info(): array {
        $disable_functions = explode(',', ini_get('disable_functions'));

        return [
            'panels' => [
                [
                    'title'    => 'PHP Info',
                    'moreinfo' => true,
                    'data'     => [
                        'PHP Version'          => PHP_VERSION,
                        'PHP Interface'        => PHP_SAPI,
                        'Max Upload File Size' => ini_get('file_uploads') ? ini_get('upload_max_filesize').'B' : 'n/a',
                        'Disabled functions'   => !empty(ini_get('disable_functions')) ?
                            ('('.count($disable_functions).') ').implode(', ', $disable_functions) : 'None',
                        'Xdebug'               => Admin::enabledDisabledBadge(
                            $this->template,
                            extension_loaded('xdebug'),
                            ' - v'.phpversion('xdebug')
                        ),
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
     * Dashboard content.
     *
     * @return string
     */
    public function dashboard(): string {
        if (isset($_GET['moreinfo'])) {
            return $this->phpinfo();
        }

        return $this->template->render('dashboards/server', [
            'show_info'      => !isset($_GET['moreinfo']),
            'panels_toggler' => false,
            'info'           => $this->info(),
            'extensions'     => get_loaded_extensions(),
        ]);
    }
}
