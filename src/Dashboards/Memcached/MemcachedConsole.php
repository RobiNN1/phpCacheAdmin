<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\ConsoleHistoryTrait;
use RobiNN\Pca\Http;
use Throwable;

trait MemcachedConsole {
    use ConsoleHistoryTrait;

    /**
     * Commands that would stream indefinitely or shut the server down.
     *
     * @var array<int, string>
     */
    private array $console_blocked = ['WATCH', 'SHUTDOWN'];

    private function consoleAjax(): string {
        header('Content-Type: application/json');

        try {
            if (isset($_GET['history'])) {
                return $this->consoleJson(['history' => $this->getConsoleHistory()]);
            }

            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return $this->consoleJson(['error' => 'Invalid CSRF token.']);
            }

            $line = trim(Http::post('command', ''));

            if ($line === '') {
                return $this->consoleJson(['error' => 'Empty command.']);
            }

            $this->storeConsoleCommand($line);

            $command_name = (string) strtok($line, ' ');

            if (in_array(strtoupper($command_name), $this->console_blocked, true)) {
                return $this->consoleJson(['error' => 'Command "'.$command_name.'" is not allowed in the console.']);
            }

            // Storage commands need their value on a second line; let users type it as a "\n" escape.
            $reply = $this->memcached->runCommand(strtr($line, ['\r\n' => "\r\n", '\n' => "\r\n"]));

            if (preg_match('/^(ERROR|CLIENT_ERROR|SERVER_ERROR)\b/', $reply) === 1) {
                return $this->consoleJson(['error' => $reply]);
            }

            return $this->consoleJson(['output' => $reply]);
        } catch (Throwable $e) {
            return $this->consoleJson(['error' => $e->getMessage()]);
        }
    }
}
