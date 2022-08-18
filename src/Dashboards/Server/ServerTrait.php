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

trait ServerTrait {
    /**
     * Show PHP Info.
     *
     * @return string
     */
    private function phpinfo(): string {
        ob_start();
        phpinfo();

        $phpinfo = preg_replace('%^.*<body>(.*)</body>.*$%ms', '$1', ob_get_clean());

        return '<div id="phpinfo">'.$phpinfo.'</div>';
    }

    /**
     * Get disabled functions.
     *
     * @return string
     */
    private function getDisabledFunctions(): string {
        $disabled_functions = 'None';

        if (ini_get('disable_functions') !== '') {
            $disable_functions = explode(',', ini_get('disable_functions'));

            $disabled_functions = '('.count($disable_functions).') ';
            $disabled_functions .= implode(', ', $disable_functions);
        }

        return $disabled_functions;
    }
}
