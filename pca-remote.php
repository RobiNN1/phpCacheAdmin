<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

/*
 * phpCacheAdmin remote endpoint - lets a dashboard on another server monitor the OPcache, APCu and realpath cache of this PHP.
 *
 * OPcache and APCu live in the memory of the PHP process, so the only way to read them is from PHP running on this server.
 * Copy this single file anywhere the web server serves PHP, set the token below (or the PCA_REMOTE_TOKEN environment variable)
 * and add the URL with the same token to the "remoteservers" option of the dashboard.
 *
 * It is read-only: nothing is changed or deleted, and cached values are never returned, only the statistics and key names.
 */

declare(strict_types=1);

namespace RobiNN\PcaRemote;

use APCUIterator;

/*
 * At least 32 characters, e.g. `php -r "echo bin2hex(random_bytes(32));"`. The PCA_REMOTE_TOKEN environment variable takes precedence.
 */
const TOKEN = '';

const MIN_TOKEN_LENGTH = 32;

/*
 * Raised only when the response changes in a way the dashboard has to know about.
 */
const PROTOCOL = 1;

function token(): string {
    $env = getenv('PCA_REMOTE_TOKEN');

    return is_string($env) && $env !== '' ? $env : TOKEN;
}

function authorized(string $sent): bool {
    $token = token();

    return strlen($token) >= MIN_TOKEN_LENGTH && hash_equals($token, $sent);
}

/**
 * @return array<string, mixed>
 */
function opcache(bool $scripts): array {
    if (!extension_loaded('Zend OPcache')) {
        return ['loaded' => false];
    }

    $configuration = @opcache_get_configuration();
    $directives = is_array($configuration) ? $configuration['directives'] : [];

    return [
        'loaded'        => true,
        'version'       => (string) phpversion('Zend OPcache'),
        'status'        => @opcache_get_status($scripts),
        'configuration' => $configuration,
        'blacklist'     => blacklist((string) ($directives['opcache.blacklist_filename'] ?? '')),
    ];
}

/**
 * The blacklist file is on this server, so it is read here. Same result as OPCachePreload::blacklist().
 *
 * @return array<string, mixed>
 */
function blacklist(string $filename): array {
    $blacklist = ['filename' => $filename, 'patterns' => [], 'unreadable' => []];

    if ($filename === '') {
        return $blacklist;
    }

    foreach (glob($filename) ?: [$filename] as $file) {
        if (!is_readable($file)) {
            $blacklist['unreadable'][] = $file;

            continue;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, ';')) {
                $blacklist['patterns'][] = $line;
            }
        }
    }

    return $blacklist;
}

/**
 * @return array<string, mixed>
 */
function apcu(bool $keys): array {
    if (!extension_loaded('apcu')) {
        return ['loaded' => false];
    }

    $enabled = apcu_enabled();

    $data = [
        'loaded'     => true,
        'version'    => (string) phpversion('apcu'),
        'cache_info' => $enabled ? @apcu_cache_info(true) : false,
        'sma_info'   => $enabled ? @apcu_sma_info() : false,
        'ini'        => array_map(static fn (array $ini): mixed => $ini['local_value'], (array) ini_get_all('apcu')),
    ];

    if ($keys && $enabled) {
        $data['keys'] = [];

        // The values stay here, only what the key list shows.
        $fields = APC_ITER_KEY | APC_ITER_TTL | APC_ITER_MEM_SIZE | APC_ITER_NUM_HITS | APC_ITER_ATIME | APC_ITER_CTIME;

        foreach (new APCUIterator(null, $fields, 0, APC_LIST_ACTIVE) as $item) {
            $data['keys'][] = $item;
        }
    }

    return $data;
}

/**
 * @return array<string, mixed>
 */
function realpathCache(): array {
    return [
        'loaded'  => true,
        'size'    => realpath_cache_size(),
        'entries' => realpath_cache_get(),
        'ini'     => [
            'realpath_cache_size' => (string) ini_get('realpath_cache_size'),
            'realpath_cache_ttl'  => (string) ini_get('realpath_cache_ttl'),
            'open_basedir'        => (string) ini_get('open_basedir'),
        ],
    ];
}

/**
 * @param array<string, mixed> $query
 *
 * @return array<string, mixed>|null
 */
function section(string $section, array $query): ?array {
    return match ($section) {
        'opcache' => opcache(isset($query['scripts'])),
        'apcu' => apcu(isset($query['keys'])),
        'realpath' => realpathCache(),
        default => null,
    };
}

/**
 * @param array<string, mixed> $data
 */
function respond(int $code, array $data): never {
    http_response_code($code);

    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    exit;
}

function run(): never {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(405, ['error' => 'Only GET requests are answered.']);
    }

    if (strlen(token()) < MIN_TOKEN_LENGTH) {
        respond(503, ['error' => 'No token is set in pca-remote.php, or it is shorter than '.MIN_TOKEN_LENGTH.' characters.']);
    }

    // A header rather than the query string, so the token does not end up in access logs.
    if (!authorized((string) ($_SERVER['HTTP_X_PCA_TOKEN'] ?? ''))) {
        respond(403, ['error' => 'Invalid token.']);
    }

    $data = section((string) ($_GET['section'] ?? ''), $_GET);

    if ($data === null) {
        respond(400, ['error' => 'Unknown section.']);
    }

    respond(200, ['protocol' => PROTOCOL, 'php' => PHP_VERSION] + $data);
}

// The tests include the file only for its functions.
if (!defined('PCA_REMOTE_NO_RUN')) {
    run();
}
