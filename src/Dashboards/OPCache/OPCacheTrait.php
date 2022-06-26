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

trait OPCacheTrait {
    /**
     * Show more info.
     *
     * @param array $status
     *
     * @return string
     */
    private function moreInfo(array $status): string {
        unset($status['scripts']);

        return $this->template->render('partials/info_table', [
            'panel_title' => 'OPCache Info',
            'array'       => Admin::convertBoolToString($status),
        ]);
    }
}
