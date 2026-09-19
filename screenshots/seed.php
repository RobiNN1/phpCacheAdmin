<?php

declare(strict_types=1);

use RobiNN\Pca\Dashboards\Memcached\PHPMem;
use RobiNN\Pca\Dashboards\Redis\Compatibility\Redis;

require_once __DIR__.'/src/functions.php';

autoload(__DIR__.'/');

header('Content-Type: application/json');

$keys_array = [
    'test-string'     => 'test',
    'test-gzcompress' => 'test',
    'test-gzencode'   => 'test',
    'test-gzdeflate'  => 'test',
    'test-object'     => 'test',
];

$errors = [];
$seeded = [];

try {
    $redis = new Redis([
        'host' => getenv('PCA_REDIS_0_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('PCA_REDIS_0_PORT') ?: 6379),
    ]);

    $redis->flushDB();

    foreach ($keys_array as $key => $value) {
        $redis->set($key, $value);
    }

    $redis->sAdd('test-set', 'value1', 'value2', 'value3');
    $redis->rPush('test-list', 'value1', 'value2', 'value3');
    $redis->zAdd('test-zset', 0, 'value1', 1, 'value2', 2, 'value3');
    $redis->hSet('test-hash', 'hashkey1', 'value1');
    $redis->hSet('test-hash', 'hashkey2', 'value2');
    $redis->hSet('test-hash', 'hashkey3', 'value3');
    $redis->streamAdd('test-stream', '*', ['field1' => 'value1', 'field2' => 'value2']);
    $redis->streamAdd('test-stream', '*', ['field3' => 'value3']);

    try {
        if (!$redis->jsonSet('test-json', '{"name":"phpCacheAdmin","numbers":[1,2,3],"nested":{"enabled":true}}')) {
            $errors[] = 'Redis: JSON.SET returned false, is the JSON module loaded?';
        }
    } catch (Throwable $e) {
        $errors[] = 'Redis: JSON.SET failed - '.$e->getMessage();
    }

    try {
        if (!$redis->vectorAdd('test-vector', 'test', [1.0, 0.2, 0.1])) {
            $errors[] = 'Redis: VADD returned false, vector sets need Redis >= 8.0 and a phpredis build that knows them.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Redis: VADD failed - '.$e->getMessage();
    }

    $seeded['redis'] = $redis->dbSize();
} catch (Throwable $e) {
    $errors[] = 'Redis: '.$e->getMessage();
}

try {
    $memcached = new PHPMem([
        'host' => getenv('PCA_MEMCACHED_0_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('PCA_MEMCACHED_0_PORT') ?: 11211),
    ]);

    if (!$memcached->isConnected()) {
        throw new RuntimeException('Not connected.');
    }

    $memcached->flush();

    foreach ($keys_array as $key => $value) {
        $memcached->set($key, $value);
    }

    $seeded['memcached'] = count($memcached->getKeys());
} catch (Throwable $e) {
    $errors[] = 'Memcached: '.$e->getMessage();
}

if (function_exists('apcu_enabled') && apcu_enabled()) {
    apcu_clear_cache();

    foreach ($keys_array as $key => $value) {
        apcu_store($key, $value);
    }

    $seeded['apcu'] = iterator_count(new APCUIterator(null, APC_ITER_KEY));
} else {
    $errors[] = 'APCu: the extension is not enabled, `apc.enable_cli=1` is needed for the built-in server.';
}

$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/src', FilesystemIterator::SKIP_DOTS)),
    '/\.php$/'
);

$compiled = 0;

if (function_exists('opcache_compile_file') && @opcache_get_status(false) !== false) {
    $loaded = array_flip(get_included_files());

    foreach ($files as $file) {
        if (!isset($loaded[$file->getPathname()])) {
            $compiled += (int) @opcache_compile_file($file->getPathname());
        }
    }

    $seeded['opcache'] = $compiled;
} else {
    $errors[] = 'OPCache: not enabled, `opcache.enable_cli=1` is needed for the built-in server.';
}

@opcache_invalidate(__FILE__, true);
clearstatcache(true, __FILE__);

http_response_code($errors === [] ? 200 : 500);

echo json_encode([
    'status' => $errors === [] ? 'ok' : 'error',
    'seeded' => $seeded,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
