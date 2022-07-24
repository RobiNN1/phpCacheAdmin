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
use RobiNN\Pca\Config;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

if (is_file(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
} else {
    require_once 'phar://twig.phar/vendor/autoload.php';

    spl_autoload_register(static function ($class_name) {
        $class_name = str_replace("RobiNN\\Pca\\", '', $class_name);
        $filename = str_replace("\\", DIRECTORY_SEPARATOR, $class_name);

        $fullpath = __DIR__.'/src/'.$filename.'.php';

        if (is_file($fullpath)) {
            require_once $fullpath;
        }
    });
}

$auth = false;

if (is_callable(Config::get('auth'))) {
    Config::get('auth')();
    $auth = true;
}

$tpl = new Template();
$admin = new Admin($tpl);

$nav = [];

foreach ($admin->getDashboards() as $d_key => $d_dashboard) {
    $d_info = $d_dashboard->getDashboardInfo();
    $nav[$d_key] = $d_info['title'];
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
        'logout_url' => $auth ? Http::queryString([], ['logout' => 'yes']) : null,
        'version'    => Admin::VERSION,
        'panels'     => $dashboard->showPanels(),
        'dashboard'  => $dashboard->dashboard(),
    ]);
}
