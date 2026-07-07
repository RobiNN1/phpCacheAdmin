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
use RobiNN\Pca\Http;

trait RedisPubSub {
    /**
     * @throws Exception
     */
    private function pubSubAjax(): string {
        header('Content-Type: application/json');

        if (isset($_POST['publish'])) {
            return $this->pubSubPublish();
        }

        if (isset($_GET['subscribe'])) {
            return $this->pubSubSubscribe();
        }

        $stats = $this->redis->pubSubStats();
        ksort($stats['channels']);

        return json_encode($stats, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @throws Exception
     */
    private function pubSubPublish(): string {
        if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
            return json_encode(['error' => 'Invalid CSRF token.'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $channel = Http::post('channel', '');

        if ($channel === '') {
            return json_encode(['error' => 'Channel name is required.'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return json_encode([
            'receivers' => $this->redis->publishMessage($channel, Http::post('message', '')),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @throws Exception
     */
    private function pubSubSubscribe(): string {
        $pattern = (string) Http::get('subscribe', '*');
        $window = min(max((int) Http::get('window', Config::get('pubsubwindow', 5)), 1), 10);

        // Release the session lock, capturing messages blocks for the whole window.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $messages = $this->redis->captureMessages($pattern === '' ? '*' : $pattern, $window, 100);

        return json_encode(['messages' => $messages], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
