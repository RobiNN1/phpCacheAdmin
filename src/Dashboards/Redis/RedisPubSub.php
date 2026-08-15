<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait RedisPubSub {
    /**
     * @throws Exception
     */
    private function pubSubAjax(): string {
        header('Content-Type: application/json');

        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            return Helpers::ajaxJson(['error' => 'Invalid CSRF token.']);
        }

        if (isset($_POST['publish'])) {
            return $this->pubSubPublish();
        }

        if (isset($_GET['subscribe'])) {
            return $this->pubSubSubscribe();
        }

        $stats = $this->redis->pubSubStats();
        ksort($stats['channels']);

        return Helpers::ajaxJson($stats);
    }

    /**
     * @throws Exception
     */
    private function pubSubPublish(): string {
        $channel = Http::post('channel', '');

        if ($channel === '') {
            return Helpers::ajaxJson(['error' => 'Channel name is required.']);
        }

        return Helpers::ajaxJson([
            'receivers' => $this->redis->publishMessage($channel, Http::post('message', '')),
        ]);
    }

    /**
     * @throws Exception
     */
    private function pubSubSubscribe(): string {
        $pattern = (string) Http::post('pattern', '*');
        $window = min(max(Http::post('window', (int) Config::get('redisoptions.pubsubwindow', 5)), 1), 10);

        // Release the session lock, capturing messages blocks for the whole window.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $messages = $this->redis->captureMessages($pattern === '' ? '*' : $pattern, $window, 100);

        return Helpers::ajaxJson(['messages' => $messages]);
    }
}
