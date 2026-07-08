<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Server;

use RobiNN\Pca\Format;

trait ServerTrait {
    use ServerResources;

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
            $this->phpPanel(),
            $this->phpConfigPanel(),
            $this->serverPanel(),
            $this->resourcesPanel(),
        ];
    }

    /**
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function phpPanel(): array {
        return [
            'title' => 'PHP',
            'data'  => [
                'PHP Version'         => PHP_VERSION,
                'Zend Engine'         => zend_version(),
                'Server API'          => PHP_SAPI,
                'Loaded php.ini file' => php_ini_loaded_file() ?: 'None',
                'Disabled functions'  => $this->getDisabledFunctions(),
            ],
        ];
    }

    /**
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function phpConfigPanel(): array {
        $memory_used = memory_get_usage(true);
        $memory_limit = Format::iniSizeToBytes((string) ini_get('memory_limit'));

        $data = [
            'Max execution time'  => ini_get('max_execution_time').'s',
            'Upload max filesize' => ini_get('upload_max_filesize'),
            'Post max size'       => ini_get('post_max_size'),
            'Default timezone'    => date_default_timezone_get(),
        ];

        if ($memory_limit > 0) {
            $usage = round(($memory_used / $memory_limit) * 100, 2);
            $data[] = ['PHP memory usage', Format::bytes($memory_used).' / '.Format::bytes($memory_limit, 0).' ('.$usage.'%)', $usage];
        } else {
            $data['PHP memory usage'] = Format::bytes($memory_used);
        }

        $data['PHP peak memory'] = Format::bytes(memory_get_peak_usage(true));

        return [
            'title' => 'PHP Configuration',
            'data'  => $data,
        ];
    }

    /**
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function serverPanel(): array {
        return [
            'title' => 'Server',
            'data'  => [
                'OS'         => PHP_OS.' ('.php_uname('m').')',
                'Host'       => php_uname('n'),
                'Web Server' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            ],
        ];
    }

    private function phpInfo(): string {
        ob_start();
        phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);

        $phpinfo = preg_replace('%^.*<body>(.*)</body>.*$%ms', '$1', ob_get_clean());

        return '<div id="phpinfo">'.$phpinfo.'</div>';
    }
}
