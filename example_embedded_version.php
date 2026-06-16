<?php
/**
 * Example: embedding phpCacheAdmin in your own website when installed via Composer.
 */

declare(strict_types=1);

use RobiNN\Pca\Admin;
use RobiNN\Pca\Config;

require __DIR__.'/../vendor/autoload.php';

/*
 * Configuration. Either:
 *   - copy vendor/robinn/phpcacheadmin/config.dist.php to phpcacheadmin.config.php and set 'pcapath' & 'url', or
 *   - set them in a .env file (requires vlucas/phpdotenv).
 *
 * 'pcapath' => '/assets/phpcacheadmin/', // where the assets (css, js, icons) are served from
 * 'url'     => '/phpcacheadmin',         // where this script is mounted
 *
 * Keep the config file outside vendor/ so Composer updates never overwrite it.
 */
Config::setConfigPath(__DIR__.'/phpcacheadmin.config.php');
Config::loadDotenv(__DIR__); // Only needed for the .env option (requires vlucas/phpdotenv).

/*
 * Authentication is optional - you'll usually place the dashboard behind your website's own secured route.
 * If you don't, enable the built-in auth by uncommenting the line below and setting `authusers` in the config.
 */
//RobiNN\Pca\Auth::check();

echo (new Admin())->render();
