<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

// Always display errors
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
error_reporting(E_ALL);

if (getenv('PCA_PHP_MEMORY_LIMIT')) {
    ini_set('memory_limit', getenv('PCA_PHP_MEMORY_LIMIT'));
}

$path = __DIR__.'/';

if (is_file(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';

    if (!extension_loaded('redis') &&
        Composer\InstalledVersions::isInstalled('predis/predis') === false &&
        is_file($path.'predis.phar')
    ) {
        require_once 'phar://'.$path.'predis.phar/vendor/autoload.php';
    }
} else {
    require_once __DIR__.'/src/functions.php';
    autoload($path);
}

$auth = false;

if (is_callable(RobiNN\Pca\Config::get('auth'))) {
    RobiNN\Pca\Config::get('auth')();
    $auth = true;
}

echo (new RobiNN\Pca\Admin())->render($auth);
