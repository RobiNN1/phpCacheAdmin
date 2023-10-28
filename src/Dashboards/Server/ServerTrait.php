<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Server;

trait ServerTrait {
    private function getDisabledFunctions(): string {
        $disabled_functions = 'None';
        $ini_value = ini_get('disable_functions');

        if ($ini_value !== false && $ini_value !== '') {
            $functions = explode(',', $ini_value);
            $disabled_functions = sprintf('(%s) %s', count($functions), implode(', ', $functions));
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
