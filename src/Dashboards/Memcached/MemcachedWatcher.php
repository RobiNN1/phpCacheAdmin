<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait MemcachedWatcher {
    /**
     * @link https://github.com/memcached/memcached/blob/master/doc/protocol.txt
     *
     * @var array<string, string>
     */
    private array $watcher_modes = [
        'fetchers'   => 'Reads (item_get)',
        'mutations'  => 'Writes (item_store)',
        'evictions'  => 'Evictions',
        'deletions'  => 'Deletions',
        'connevents' => 'Connections',
    ];

    /**
     * @var array<int, string>
     */
    private array $watcher_default_modes = ['mutations', 'evictions', 'deletions'];

    /**
     * How long a single capture request holds a watcher open, in seconds.
     */
    private int $watcher_window = 5;

    /**
     * Log lines one capture request returns at most, so a busy server cannot flood the response.
     */
    private int $watcher_limit = 1_000;

    /**
     * @return array<string, mixed>
     */
    private function watcherTab(): array {
        if (version_compare($this->version(), '1.4.15', '<')) {
            return ['tab_error' => 'Watchers require Memcached >= 1.4.15, the server runs '.$this->version().'.'];
        }

        return [
            'modes'   => $this->watcher_modes,
            'default' => $this->watcher_default_modes,
            'window'  => $this->watcher_window,
        ];
    }

    private function watcherAjax(): string {
        header('Content-Type: application/json');

        $window = min(max((int) Http::get('window', $this->watcher_window), 1), 10);
        $modes = array_values(array_intersect(explode(',', (string) Http::get('modes', '')), array_keys($this->watcher_modes)));

        if ($modes === []) {
            return Helpers::ajaxJson(['error' => 'Pick at least one thing to watch.']);
        }

        // Release the session lock, the capture blocks for the whole window.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $skipped = $this->skippedLines();
            $logs = $this->captureLogs($modes, $window, $this->watcher_limit);

            return Helpers::ajaxJson([
                'logs'    => $logs,
                // The server drops lines instead of waiting for a slow watcher.
                'skipped' => max(0, $this->skippedLines() - $skipped),
            ]);
        } catch (MemcachedException $e) {
            return Helpers::ajaxJson(['error' => $e->getMessage()]);
        }
    }

    /**
     * Lines the server threw away because a watcher could not keep up.
     */
    private function skippedLines(): int {
        try {
            return (int) ($this->memcached->getServerStats()['log_watcher_skipped'] ?? 0);
        } catch (MemcachedException) {
            return 0;
        }
    }

    private function version(): string {
        try {
            return $this->memcached->version();
        } catch (MemcachedException) {
            return '0';
        }
    }

    /**
     * A watcher takes the connection over, so it cannot share the one the rest of the dashboard uses.
     *
     * @return resource
     *
     * @throws MemcachedException
     */
    private function openWatcher(string $modes) {
        $server = $this->servers[$this->current_server];
        $address = isset($server['path']) ? 'unix://'.$server['path'] : 'tcp://'.$server['host'].':'.($server['port'] ?? 11211);

        $stream = @stream_socket_client($address, $error_code, $error_message, 3);

        if ($stream === false) {
            throw new MemcachedException('Could not open a watcher connection: '.$error_code.' - '.$error_message);
        }

        stream_set_timeout($stream, 3);
        fwrite($stream, 'watch '.$modes."\r\n");

        $reply = trim((string) fgets($stream));

        if ($reply !== 'OK') {
            fclose($stream);

            throw new MemcachedException(sprintf(
                'The server refused to watch "%s": %s',
                $modes,
                $reply !== '' ? $reply : 'no reply'
            ));
        }

        stream_set_blocking($stream, false);

        return $stream;
    }

    /**
     * Collect log lines for a limited time window.
     *
     * @param array<int, string> $modes
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MemcachedException
     */
    public function captureLogs(array $modes, int $seconds, int $limit): array {
        $stream = $this->openWatcher(implode(' ', $modes));

        $logs = [];
        $buffer = '';
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline && count($logs) < $limit) {
            $read = [$stream];
            $write = null;
            $except = null;
            $left = max($deadline - microtime(true), 0);

            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1_000_000)) < 1) {
                continue;
            }

            $chunk = (string) fread($stream, 65_536);

            // A dropped connection stays "readable" forever, keeping it would spin the loop until the deadline.
            if ($chunk === '' && feof($stream)) {
                break;
            }

            $buffer .= $chunk;

            // Whatever follows the last newline is half a line, keep it for the next read.
            $lines = explode("\n", $buffer);
            $buffer = (string) array_pop($lines);

            foreach ($lines as $line) {
                $log = $this->parseWatchLine($line);

                if ($log !== null) {
                    $logs[] = $log;
                }
            }
        }

        fclose($stream);

        return array_slice($logs, 0, $limit);
    }

    /**
     * ts=1786477627.620824 gid=4 type=item_store key=foo%3Abar status=stored cmd=set ttl=0 clsid=1 size=5
     *
     * @return array<string, mixed>|null
     */
    public function parseWatchLine(string $line): ?array {
        $fields = [];

        foreach (explode(' ', trim($line)) as $pair) {
            if (str_contains($pair, '=')) {
                [$field, $value] = explode('=', $pair, 2);
                $fields[$field] = $value;
            }
        }

        if (!isset($fields['ts'], $fields['type'])) {
            return null;
        }

        $log = [
            'time' => (float) $fields['ts'],
            'gid'  => (int) ($fields['gid'] ?? 0),
            'type' => $fields['type'],
            // Keys are logged urlencoded.
            'key'  => isset($fields['key']) ? urldecode($fields['key']) : '',
        ];

        unset($fields['ts'], $fields['gid'], $fields['type'], $fields['key']);

        $log['fields'] = $fields;

        return $log;
    }
}
