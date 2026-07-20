<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Redis;

use Exception;
use RobiNN\Pca\Dashboards\DashboardException;
use RobiNN\Pca\Http;

trait RedisProfiler {
    /**
     * How long a single capture request holds a MONITOR open, in seconds.
     */
    private int $profiler_window = 5;

    /**
     * Commands one capture request returns at most, so a busy server cannot flood the response.
     */
    private int $profiler_limit = 1_000;

    /**
     * Bytes of a single argument the response keeps, a screen line has no use for a whole serialized
     * object and a capture full of them would not fit in the memory limit either.
     */
    private int $profiler_arg_max = 512;

    /**
     * @return array<string, mixed>
     */
    private function profilerTab(): array {
        if (!$this->isCommandSupported('MONITOR')) {
            return ['tab_error' => 'MONITOR command is disabled on this server.'];
        }

        return ['window' => $this->profiler_window];
    }

    /**
     * @throws Exception
     */
    private function profilerAjax(): string {
        header('Content-Type: application/json');

        $window = min(max((int) Http::get('window', $this->profiler_window), 1), 10);

        // Release the session lock, the capture blocks for the whole window.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $commands = $this->captureCommands($window, $this->profiler_limit);
        } catch (DashboardException $e) {
            return json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return json_encode(['commands' => $commands], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, string>
     */
    private function monitorTargets(): array {
        $server = $this->servers[$this->current_server];
        $scheme = ($server['scheme'] ?? 'tcp') === 'tls' ? 'tls' : 'tcp';

        if (!empty($server['nodes']) && is_array($server['nodes'])) {
            $targets = [];

            foreach ($server['nodes'] as $node) {
                $node = (string) $node;
                // A seed can name its own scheme (tls://host:port), the rest follow the server.
                $targets[$node] = str_contains($node, '://') ? $node : $scheme.'://'.$node;
            }

            return $targets;
        }

        if (isset($server['path'])) {
            return [(string) $server['path'] => 'unix://'.$server['path']];
        }

        $address = $server['host'].':'.($server['port'] ?? 6379);

        return [$address => $scheme.'://'.$address];
    }

    /**
     * @param array<int, string> $args
     */
    private function respCommand(array $args): string {
        $command = '*'.count($args)."\r\n";

        foreach ($args as $arg) {
            $command .= '$'.strlen($arg)."\r\n".$arg."\r\n";
        }

        return $command;
    }

    /**
     * MONITOR takes the connection over for as long as it runs, so it cannot share the one the rest of the dashboard uses.
     *
     * @param array<string, mixed> $server
     *
     * @return resource|null
     */
    private function openMonitor(string $uri, array $server) {
        $context = stream_context_create(['ssl' => $server['ssl'] ?? []]);
        $stream = @stream_socket_client($uri, $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            return null;
        }

        stream_set_timeout($stream, 3);

        if (isset($server['password'])) {
            $auth = isset($server['username'])
                ? ['AUTH', (string) $server['username'], (string) $server['password']]
                : ['AUTH', (string) $server['password']];

            fwrite($stream, $this->respCommand($auth));

            if (!str_starts_with((string) fgets($stream), '+OK')) {
                fclose($stream);

                return null;
            }
        }

        fwrite($stream, $this->respCommand(['MONITOR']));

        if (!str_starts_with((string) fgets($stream), '+OK')) {
            fclose($stream);

            return null;
        }

        stream_set_blocking($stream, false);

        return $stream;
    }

    /**
     * Collect commands from every node for a limited time window.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws DashboardException
     */
    public function captureCommands(int $seconds, int $limit): array {
        $server = $this->resolvePassword($this->servers[$this->current_server]);
        $streams = [];
        $nodes = [];
        $skipped = [];

        foreach ($this->monitorTargets() as $node => $uri) {
            $stream = $this->openMonitor($uri, $server);

            if ($stream === null) {
                $skipped[] = $node;

                continue;
            }

            $streams[] = $stream;
            // stream_select() renumbers the array it is given, so the node has to be found by the stream itself.
            $nodes[(int) $stream] = $node;
        }

        if ($streams === []) {
            throw new DashboardException('Could not open a MONITOR connection ('.implode(', ', $skipped).').');
        }

        $commands = [];
        $buffers = [];
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline && count($commands) < $limit && $streams !== []) {
            $read = $streams;
            $write = null;
            $except = null;
            $left = max($deadline - microtime(true), 0);

            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1_000_000)) < 1) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = (string) fread($stream, 65_536);

                // A dropped connection stays "readable" forever, keeping it would spin the loop at full CPU until the deadline.
                if ($chunk === '' && feof($stream)) {
                    fclose($stream);
                    $streams = array_filter($streams, static fn ($open): bool => $open !== $stream);

                    continue;
                }

                $node = $nodes[(int) $stream];
                $buffers[$node] = ($buffers[$node] ?? '').$chunk;

                // Whatever follows the last newline is half a line, keep it for the next read.
                $lines = explode("\r\n", $buffers[$node]);
                $buffers[$node] = (string) array_pop($lines);

                // A fragment this big is one giant value, showing it is not worth risking the memory limit.
                // The rest of the line will fail to parse and the capture recovers on the next complete one.
                if (strlen($buffers[$node]) > 4_194_304) {
                    $buffers[$node] = '';
                }

                foreach ($lines as $line) {
                    $command = $this->parseMonitorLine($line, count($nodes) > 1 ? $node : '');

                    if ($command !== null) {
                        $commands[] = $command;
                    }
                }
            }
        }

        foreach ($streams as $stream) {
            fclose($stream);
        }

        usort($commands, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        return array_slice($commands, 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseMonitorLine(string $line, string $node = ''): ?array {
        // +1700000000.123456 [0 127.0.0.1:6379] "SET" "key" "value"
        if (!preg_match('/^\+?(\d+\.\d+) \[(\d+) (\S+)] (.*)$/', trim($line), $matches)) {
            return null;
        }

        $args = $this->parseMonitorArgs($matches[4]);

        if ($args === []) {
            return null;
        }

        return [
            'time'    => (float) $matches[1],
            'db'      => (int) $matches[2],
            'addr'    => $matches[3],
            'command' => strtoupper((string) array_shift($args)),
            'args'    => $args,
            'node'    => $node,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseMonitorArgs(string $raw): array {
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $matches) === 0) {
            return [];
        }

        return array_map(function (string $arg): string {
            $arg = stripcslashes($arg);

            return strlen($arg) > $this->profiler_arg_max ? substr($arg, 0, $this->profiler_arg_max).'…' : $arg;
        }, $matches[1]);
    }
}
