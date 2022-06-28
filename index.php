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

$admin = new Admin();
$tpl = new Template();

$admin->setDashboard(new ServerDashboard($tpl));
$admin->setDashboard(new RedisDashboard($tpl));
$admin->setDashboard(new MemcachedDashboard($tpl));
$admin->setDashboard(new OPCacheDashboard($tpl));

$current = $admin->currentDashboard();
$dashboard = $admin->getDashboard($current);
$info = $dashboard->getDashboardInfo();

$tpl->addTplGlobal('current', $current);
$tpl->addTplGlobal('color', $info['color']);

if (isset($_GET['ajax'])) {
    echo $dashboard->ajax();
} else {
    $nav = [];

    foreach ($admin->getDashboards() as $n_key => $n_dashboard) {
        if ($n_dashboard->check()) {
            $n_info = $n_dashboard->getDashboardInfo();
            $nav[$n_key] = $n_info['title'];
        }
    }

    echo $tpl->render('layout', [
        'site_title' => $info['title'],
        'nav'        => $nav,
        'version'    => Admin::VERSION,
        'back'       => isset($_GET['moreinfo']) || isset($_GET['view']) || isset($_GET['form']),
        'back_url'   => Admin::queryString(['db']),
        'panels'     => $dashboard->showPanels(),
        'dashboard'  => $dashboard->dashboard(),
    ]);
}
