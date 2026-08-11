<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\ConsoleTrait;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use Throwable;

trait MemcachedConsole {
    use ConsoleTrait;

    /**
     * Commands that would stream indefinitely or shut the server down.
     *
     * @var array<int, string>
     */
    private array $console_blocked = ['WATCH', 'SHUTDOWN'];

    /**
     * Blocked commands the dashboard covers with a tab, so the error can point there instead of being a dead end.
     *
     * @var array<string, string>
     */
    private array $console_command_tabs = [
        'WATCH' => 'watcher',
    ];

    /**
     * Allowed commands whose raw reply the dashboard also renders as a formatted tab, offered after the output.
     *
     * @var array<string, string>
     */
    private array $console_command_views = [
        'STATS'                => 'moreinfo',
        'STATS SETTINGS'       => 'moreinfo',
        'STATS ITEMS'          => 'items',
        'STATS SLABS'          => 'slabs',
        'STATS SIZES'          => 'analysis',
        'STATS CONNS'          => 'connections',
        'STATS CACHEDUMP'      => 'keys',
        'LRU_CRAWLER METADUMP' => 'keys',
        'LRU_CRAWLER MGDUMP'   => 'keys',
    ];

    private function consoleAjax(): string {
        header('Content-Type: application/json');

        try {
            if (isset($_GET['history'])) {
                return Helpers::ajaxJson(['history' => $this->getConsoleHistory()]);
            }

            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return Helpers::ajaxJson(['error' => 'Invalid CSRF token.']);
            }

            $line = trim(Http::post('command', ''));

            if ($line === '') {
                return Helpers::ajaxJson(['error' => 'Empty command.']);
            }

            $this->storeConsoleCommand($line);

            $args = preg_split('/\s+/', $line) ?: [];

            if (in_array(strtoupper($args[0]), $this->console_blocked, true)) {
                return Helpers::ajaxJson([
                    'error' => 'Command "'.$args[0].'" is not allowed in the console.',
                    ...$this->consoleTabHint($this->console_command_tabs, $args, 'Open the %s tab'),
                ]);
            }

            // Storage commands need their value on a second line; let users type it as a "\n" escape.
            $reply = $this->memcached->runCommand(strtr($line, ['\r\n' => "\r\n", '\n' => "\r\n"]));

            if (preg_match('/^(ERROR|CLIENT_ERROR|SERVER_ERROR)\b/', $reply) === 1) {
                return Helpers::ajaxJson(['error' => $reply]);
            }

            return Helpers::ajaxJson([
                'output' => $reply,
                ...$this->consoleTabHint($this->console_command_views, $args, 'See it formatted on the %s tab'),
            ]);
        } catch (Throwable $e) {
            return Helpers::ajaxJson(['error' => $e->getMessage()]);
        }
    }
}
