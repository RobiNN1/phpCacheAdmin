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

use RobiNN\Pca\Helpers;

trait ServerTrait {
    private function getDisabledFunctions(): string {
        $disabled_functions = 'None';
        $ini_value = ini_get('disable_functions');

        if ($ini_value !== false && $ini_value !== '') {
            $disable_functions = explode(',', $ini_value);

            $disabled_functions = '('.count($disable_functions).') ';
            $disabled_functions .= implode(', ', $disable_functions);
        }

        return $disabled_functions;
    }

    private function panels(): string {
        $panels = [
            [
                'title'    => 'PHP Info',
                'moreinfo' => true,
                'data'     => [
                    'PHP Version'        => PHP_VERSION,
                    'PHP Interface'      => PHP_SAPI,
                    'Disabled functions' => $this->getDisabledFunctions(),
                    'Xdebug'             => extension_loaded('xdebug') ? 'Enabled - v'.phpversion('xdebug') : 'Disabled',
                    'Realpath Cache (total/used)' => ini_get('realpath_cache_size') .'/'. Helpers::convertToReadableSize(realpath_cache_size())
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
        ];

        return $this->template->render('partials/info', ['panels' => $panels]);
    }

    private function phpInfo(): string {
        ob_start();
        phpinfo();

        $phpinfo = preg_replace('%^.*<body>(.*)</body>.*$%ms', '$1', ob_get_clean());

        return '<div id="phpinfo">'.$phpinfo.'</div>';
    }
}
