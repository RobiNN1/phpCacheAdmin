<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use Exception;
use JsonException;
use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use Throwable;

trait MemcachedConsole {
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

    /**
     * @param array<string, mixed> $data
     */
    private function consoleJson(array $data): string {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            return 'JSON error. '.$e->getMessage();
        }
    }

    private function consoleHistoryFile(): string {
        $dir = Config::get('tmpdir', __DIR__.'/../../../tmp').'/console';
        $hash = md5(Helpers::getServerTitle($this->servers[$this->current_server]).Config::get('hash', 'pca'));

        return $dir.'/memcached_history_'.$hash.'.json';
    }

    /**
     * @return array<int, string>
     *
     * @throws JsonException
     */
    private function getConsoleHistory(): array {
        $file = $this->consoleHistoryFile();

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    /**
     * @throws JsonException
     */
    private function storeConsoleCommand(string $command): void {
        if ($command === '') {
            return;
        }

        $history = $this->getConsoleHistory();

        if (end($history) === $command) {
            return;
        }

        $history[] = $command;

        $consolehistory = 100;

        if (count($history) > $consolehistory) {
            $history = array_slice($history, -$consolehistory);
        }

        $file = $this->consoleHistoryFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        try {
            file_put_contents($file, json_encode(array_values($history), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        } catch (Exception) {
        }
    }
}
