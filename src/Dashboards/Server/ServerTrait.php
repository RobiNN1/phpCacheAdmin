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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function panels(): array {
        return [
            [
                'title' => 'PHP Info',
                'data'  => [
                    'PHP Version'         => PHP_VERSION,
                    'Disabled functions'  => $this->getDisabledFunctions(),
                    'Loaded php.ini file' => php_ini_loaded_file(),
                    'Memory limit'        => ini_get('memory_limit'),
                    'Max execution time'  => ini_get('max_execution_time').'s',
                    'Xdebug'              => extension_loaded('xdebug') ? 'Enabled - v'.phpversion('xdebug') : 'Disabled',
                ],
            ],
            [
                'title' => 'Server Info',
                'data'  => [
                    'OS'         => PHP_OS,
                    'Server'     => php_uname(),
                    'Web Server' => $_SERVER['SERVER_SOFTWARE'],
                    'Server API' => PHP_SAPI,
                    'User Agent' => $_SERVER['HTTP_USER_AGENT'],
                ],
            ],
        ];
    }

    private function phpInfo(): string {
        ob_start();
        phpinfo();

        $phpinfo = preg_replace('%^.*<body>(.*)</body>.*$%ms', '$1', ob_get_clean());

        return '<div id="phpinfo">'.$phpinfo.'</div>';
    }
}
