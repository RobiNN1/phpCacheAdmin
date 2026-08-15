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
     * Commands that would block the PHP request, hijack the connection, or crash the server.
     *
     * @var array<int, string>
     */
    private array $console_blocked = ['WATCH', 'SHUTDOWN', 'CACHE_MEMLIMIT', 'SLABS', 'LRU', 'VERBOSITY'];

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

    /**
     * Commands whose value follows on a second line, and the argument that declares how many bytes it is.
     *
     * @var array<string, int>
     */
    private array $console_data_commands = [
        'SET' => 4, 'ADD' => 4, 'REPLACE' => 4, 'APPEND' => 4, 'PREPEND' => 4, 'CAS' => 4, 'MS' => 2,
    ];

    private function consoleAjax(): string {
        header('Content-Type: application/json');

        if (!$this->consoleEnabled()) {
            return Helpers::ajaxJson(['error' => 'The console is disabled.']);
        }

        try {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return Helpers::ajaxJson(['error' => 'Invalid CSRF token.']);
            }

            if (isset($_GET['history'])) {
                return Helpers::ajaxJson(['history' => $this->getConsoleHistory()]);
            }

            $line = trim(Http::post('command', ''));

            if ($line === '') {
                return Helpers::ajaxJson(['error' => 'Empty command.']);
            }

            $this->storeConsoleCommand($line);

            // Storage commands need their value on a second line. Let users type it as a "\n" escape.
            $payload = $this->consolePayload($line);
            $args = preg_split('/\s+/', trim(explode("\r\n", $payload, 2)[0])) ?: [];

            $blocked = $this->blockedCommand([...$this->console_blocked, ...$this->configuredBlockedCommands('memcachedoptions')], $args);

            if ($blocked !== null) {
                return Helpers::ajaxJson([
                    'error' => 'Command "'.$blocked.'" is not allowed in the console.',
                    ...$this->consoleTabHint($this->console_command_tabs, $args, 'Open the %s tab'),
                ]);
            }

            $invalid = $this->dataBlockError($payload, $args);

            if ($invalid !== null) {
                return Helpers::ajaxJson(['error' => $invalid]);
            }

            $reply = $this->memcached->runCommand($payload);

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

    private function consolePayload(string $line): string {
        return preg_replace('/\r\n|\r|\n/', "\r\n", strtr($line, ['\r\n' => "\r\n", '\n' => "\r\n"]));
    }

    /**
     * Only a storage command may span two lines, and only for exactly the number of bytes it declares.
     *
     * @param array<int, string> $args The first line, split into tokens.
     */
    private function dataBlockError(string $payload, array $args): ?string {
        $newline = strpos($payload, "\r\n");

        if ($newline === false) {
            return null;
        }

        $command = strtoupper($args[0] ?? '');
        $position = $this->console_data_commands[$command] ?? null;

        if ($position === null) {
            return sprintf('Only a storage command can be followed by a value, "%s" cannot.', $args[0] ?? '');
        }

        $bytes = $args[$position] ?? '';

        if (!ctype_digit($bytes) || strlen(substr($payload, $newline + 2)) !== (int) $bytes) {
            return sprintf('%s must be followed by exactly the number of bytes it declares.', $command);
        }

        return null;
    }
}
