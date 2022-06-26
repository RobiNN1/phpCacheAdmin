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

use RobiNN\Pca\Admin;
use RobiNN\Pca\Dashboards\Memcached\MemcachedDashboard;
use RobiNN\Pca\Dashboards\OPCache\OPCacheDashboard;
use RobiNN\Pca\Dashboards\Redis\RedisDashboard;
use RobiNN\Pca\Dashboards\Server\ServerDashboard;
use RobiNN\Pca\Template;

require_once __DIR__.'/vendor/autoload.php';

$tpl = new Template();

switch (Admin::currentDashboard()) {
    case 'redis':
        $color = 'red';
        $dashboard = new RedisDashboard($tpl);
        break;
    case 'memcached':
        $color = 'emerald';
        $dashboard = new MemcachedDashboard($tpl);
        break;
    case 'opcache':
        $color = 'sky';
        $dashboard = new OPCacheDashboard($tpl);
        break;
    default:
        $color = 'slate';
        $dashboard = new ServerDashboard($tpl);
        break;
}

$tpl->addTplGlobal('current', Admin::currentDashboard());
$tpl->addTplGlobal('color', $color);

if (isset($_GET['ajax'])) {
    echo $dashboard->ajax();
} else {
    $nav = [];

    $nav['server'] = 'Server';

    if (Admin::checkRedis()) {
        $nav['redis'] = 'Redis';
    }

    if (Admin::checkMemcached()) {
        $nav['memcached'] = 'Memcache(d)';
    }

    if (Admin::checkOpCache()) {
        $nav['opcache'] = 'OPCache';
    }

    echo $tpl->render('layout', [
        'site_title' => $nav[Admin::currentDashboard()],
        'nav'        => $nav,
        'back'       => isset($_GET['moreinfo']) || isset($_GET['view']) || isset($_GET['form']),
        'back_url'   => Admin::queryString(['db']),
        'version'    => Admin::VERSION,
        'dashboard'  => $dashboard->dashboard(),
    ]);
}
