<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards;

use RobiNN\Pca\Dashboards\RemoteClient;
use RobiNN\PcaRemote;

if (!defined('PCA_REMOTE_NO_RUN')) {
    define('PCA_REMOTE_NO_RUN', true);
}

require_once __DIR__.'/../../pca-remote.php';

/**
 * Answers from pca-remote.php in this process, so a remote server shows the same caches as the local one.
 */
final class InProcessRemoteClient extends RemoteClient {
    public int $requests = 0;

    /**
     * @var array<string, mixed>|null Replaces the response of pca-remote.php.
     */
    public ?array $response = null;

    protected function request(string $url): string {
        $this->requests++;

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $data = $this->response ?? ['protocol' => PcaRemote\PROTOCOL, 'php' => PHP_VERSION] + (PcaRemote\section((string) $query['section'], $query) ?? []);

        return (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
