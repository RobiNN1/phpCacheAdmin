<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use RobiNN\Pca\Csrf;
use RobiNN\Pca\Dashboards\ConsoleHistoryTrait;
use RobiNN\Pca\Http;
use Throwable;

trait RedisConsole {
    use ConsoleHistoryTrait;

    /**
     * Commands that would block the PHP request, hijack the connection, or crash the server.
     *
     * @var array<int, string>
     */
    private array $console_blocked = [
        'MONITOR', 'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'SUNSUBSCRIBE',
        'SYNC', 'PSYNC', 'WAIT', 'WAITAOF', 'DEBUG', 'SHUTDOWN',
        'BLPOP', 'BRPOP', 'BLMOVE', 'BLMPOP', 'BRPOPLPUSH', 'BZPOPMIN', 'BZPOPMAX', 'BZMPOP',
        'XREAD', 'XREADGROUP',
    ];

    private function consoleAjax(): string {
        header('Content-Type: application/json');

        try {
            if (isset($_GET['history'])) {
                return $this->consoleJson(['history' => $this->getConsoleHistory()]);
            }

            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                return $this->consoleJson(['error' => 'Invalid CSRF token.']);
            }

            $line = Http::post('command', '');
            $args = $this->parseCommandLine($line);

            if ($args === []) {
                return $this->consoleJson(['error' => 'Empty command.']);
            }

            $this->storeConsoleCommand(trim($line));

            if (in_array(strtoupper($args[0]), $this->console_blocked, true)) {
                return $this->consoleJson(['error' => 'Command "'.$args[0].'" is not allowed in the console.']);
            }

            return $this->consoleJson(['output' => $this->formatReply($this->redis->consoleCommand($args))]);
        } catch (Throwable $e) {
            return $this->consoleJson(['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseCommandLine(string $line): array {
        $args = [];
        $current = '';
        $has_token = false;
        $in_single = false;
        $in_double = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($in_double) {
                if ($char === '\\' && $i + 1 < $length) {
                    $next = $line[++$i];
                    $current .= ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\'][$next] ?? $next;
                } elseif ($char === '"') {
                    $in_double = false;
                } else {
                    $current .= $char;
                }
            } elseif ($in_single) {
                if ($char === "'") {
                    $in_single = false;
                } else {
                    $current .= $char;
                }
            } elseif ($char === '"') {
                $in_double = true;
                $has_token = true;
            } elseif ($char === "'") {
                $in_single = true;
                $has_token = true;
            } elseif ($char === ' ' || $char === "\t") {
                if ($has_token) {
                    $args[] = $current;
                    $current = '';
                    $has_token = false;
                }
            } else {
                $current .= $char;
                $has_token = true;
            }
        }

        if ($has_token) {
            $args[] = $current;
        }

        return $args;
    }

    private function formatReply(mixed $reply, int $indent = 0, bool $quote = false): string {
        $pad = str_repeat(' ', $indent);

        if ($reply === null || $reply === false) {
            return $pad.'(nil)';
        }

        if ($reply === true) {
            return $pad.'OK';
        }

        if (is_int($reply)) {
            return $pad.'(integer) '.$reply;
        }

        if (is_float($reply)) {
            return $pad.'(double) '.$reply;
        }

        if (is_array($reply)) {
            if ($reply === []) {
                return $pad.'(empty array)';
            }

            $lines = [];
            $index = 1;
            $width = strlen((string) count($reply));

            foreach ($reply as $key => $item) {
                $prefix = is_string($key) ? $key.') ' : str_pad((string) $index++, $width, ' ', STR_PAD_LEFT).') ';
                $formatted = $this->formatReply($item, $indent + strlen($prefix), true);
                // Keep the prefix on the same line as the (already indented) value.
                $lines[] = $pad.$prefix.substr($formatted, $indent + strlen($prefix));
            }

            return implode("\n", $lines);
        }

        $string = (string) $reply;

        return $pad.($quote ? '"'.addcslashes($string, "\0..\37\"\\").'"' : $string);
    }
}
