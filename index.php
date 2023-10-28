<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

use Composer\InstalledVersions;
use RobiNN\Pca\Admin;
use RobiNN\Pca\Config;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

if (PHP_VERSION_ID < 70400) {
    exit('<strong>PHP >= 7.4 is required.</strong><br>The current version of php is: '.PHP_VERSION);
}

// Always display errors
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
error_reporting(E_ALL);

$path = __DIR__.'/';

if (is_file(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';

    if (!extension_loaded('redis') &&
        InstalledVersions::isInstalled('predis/predis') === false &&
        is_file($path.'predis.phar')
    ) {
        require_once 'phar://'.$path.'predis.phar/vendor/autoload.php';
    }
} else {
    require_once __DIR__.'/src/functions.php';
    autoload($path);
}

if (is_callable(Config::get('auth'))) {
    Config::get('auth')();
    $auth = true;
}

$tpl = new Template();
$admin = new Admin($tpl);

$nav = [];

foreach ($admin->dashboards() as $d_key => $d_dashboard) {
    $d_info = $d_dashboard->dashboardInfo();
    $nav[$d_key] = [
        'title' => $d_info['title'],
        'icon'  => $d_info['icon'] ?? 'dashboards/'.$d_key,
    ];
}

$current = $admin->currentDashboard();
$dashboard = $admin->getDashboard($current);
$info = $dashboard->dashboardInfo();

$tpl->addGlobal('current', $current);

if (isset($_GET['ajax']) && method_exists($dashboard, 'ajax')) {
    echo $dashboard->ajax();
} else {
    if (isset($_GET['moreinfo']) || isset($_GET['form']) || isset($_GET['view'], $_GET['key'])) {
        $back_url = Http::queryString(['db', 's']);
    }

    if (isset($info['colors'])) {
        $colors = '';

        foreach ((array) $info['colors'] as $key => $color) {
            $colors .= '--primary-color-'.$key.':'.$color.';';
        }
    }

    echo $tpl->render('layout', [
        'colors'     => $colors ?? null,
        'site_title' => $info['title'],
        'nav'        => $nav,
        'logout_url' => isset($auth) ? Http::queryString([], ['logout' => 'yes']) : null,
        'version'    => Admin::VERSION,
        'repo'       => 'https://github.com/RobiNN1/phpCacheAdmin',
        'back_url'   => $back_url ?? null,
        'dashboard'  => $dashboard->dashboard(),
    ]);
}
