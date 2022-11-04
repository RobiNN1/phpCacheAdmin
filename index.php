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
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;

const ROOT_PATH = __DIR__.'/';

if (is_file(ROOT_PATH.'vendor/autoload.php')) {
    require_once ROOT_PATH.'vendor/autoload.php';
} else {
    if (is_file(ROOT_PATH.'twig.phar')) {
        require_once 'phar://'.ROOT_PATH.'twig.phar/vendor/autoload.php';
    }

    if (!extension_loaded('redis') && is_file(ROOT_PATH.'predis.phar')) {
        require_once 'phar://'.ROOT_PATH.'predis.phar/vendor/autoload.php';
    }

    spl_autoload_register(static function ($class_name) {
        $class_name = str_replace("RobiNN\\Pca\\", '', $class_name);
        $filename = str_replace("\\", DIRECTORY_SEPARATOR, $class_name);

        $fullpath = ROOT_PATH.'src/'.$filename.'.php';

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
    $nav[$d_key] = [
        'title' => $d_info['title'],
        'icon'  => $d_info['icon'] ?? $d_key,
    ];
}

$current = $admin->currentDashboard();
$dashboard = $admin->getDashboard($current);
$info = $dashboard->getDashboardInfo();

$tpl->addGlobal('current', $current);

if (isset($_GET['ajax'])) {
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
        'logout_url' => $auth ? Http::queryString([], ['logout' => 'yes']) : null,
        'version'    => Admin::VERSION,
        'repo'       => 'https://github.com/RobiNN1/phpCacheAdmin',
        'git'        => $admin->getGitInfo(__DIR__.'/.git'),
        'back_url'   => $back_url ?? null,
        'panels'     => $dashboard->showPanels(),
        'dashboard'  => $dashboard->dashboard(),
    ]);
}
