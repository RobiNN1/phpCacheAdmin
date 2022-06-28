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
use RobiNN\Pca\Template;

require_once __DIR__.'/vendor/autoload.php';

$admin = new Admin();
$tpl = new Template();

$nav = [];

foreach (Admin::getConfig('dashboards') as $class) {
    $object = new $class($tpl);
    $admin->setDashboard($object);

    if ($object->check()) {
        $d_info = $object->getDashboardInfo();
        $nav[$d_info['key']] = $d_info['title'];
    }
}

$current = $admin->currentDashboard();
$dashboard = $admin->getDashboard($current);
$info = $dashboard->getDashboardInfo();

$tpl->addTplGlobal('current', $current);
$tpl->addTplGlobal('color', $info['color']);

if (isset($_GET['ajax'])) {
    echo $dashboard->ajax();
} else {
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
