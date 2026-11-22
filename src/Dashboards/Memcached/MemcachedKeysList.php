<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Memcached;

use RobiNN\Pca\Format;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\KeyFilters;

trait MemcachedKeysList {
    /**
     * Keys in the dump that have already expired on the server clock, the server removes them lazily.
     */
    private int $expired_keys = 0;

    private ?string $filter_error = null;

    /**
     * The search and filters from the URL.
     */
    public function keyFilters(): KeyFilters {
        return new KeyFilters(['nf', 'noexp', 'expin', 'idle', 'minsize', 'maxsize']);
    }

    /**
     * Template data of the filters panel, after getAllKeys() has run. Filters need a metadump (>= 1.5.19).
     *
     * @return array<string, mixed>
     *
     * @throws MemcachedException
     */
    private function keyFiltersData(int $matching): array {
        if (version_compare($this->memcached->version(), '1.5.19', '<')) {
            return ['key_filters' => null];
        }

        $note = $this->expired_keys > 0 ? Format::number($this->expired_keys).' expired not shown' : '';

        return ['key_filters' => $this->keyFilters()->view($matching, [], 'Delete', $note)];
    }

    /**
     * Metadump lines of the keys that match the search and filters, expired keys are left out.
     *
     * @return array<int, string>
     *
     * @throws MemcachedException
     */
    public function getAllKeys(): array {
        $filters = $this->keyFilters();
        $search = $filters->values['s'];
        $this->template->addGlobal('search_value', $search);

        $lines = $this->memcached->getKeys();
        $now = $this->memcached->serverTime();

        $matcher = $filters->matcher();
        $this->filter_error = $filters->error;

        // Keys are urlencoded in the lines but these characters never are, so preg_grep() can prefilter them.
        if ($search !== '' && $filters->values['match'] !== 'regex' && preg_match('/^[A-Za-z0-9._~-]+$/', $search) === 1) {
            $candidates = preg_grep('/'.preg_quote($search, '/').'/i', $lines);

            if ($candidates !== false) {
                $lines = $candidates;
            }
        }

        $with_filters = $filters->active();
        $this->expired_keys = 0;
        $filtered = [];

        foreach ($lines as $line) {
            $exp = $this->lineField($line, 'exp');

            if ($exp !== null && $exp !== -1 && $exp <= $now) {
                $this->expired_keys++;
                continue;
            }

            if ($matcher !== null) {
                $space = strpos($line, ' ');
                $key = substr($line, 4, ($space === false ? strlen($line) : $space) - 4);

                if (!$matcher(str_contains($key, '%') ? urldecode($key) : $key)) {
                    continue;
                }
            }

            if ($with_filters && !$this->lineMatchesFilters($line, $filters, $exp ?? -1, $now)) {
                continue;
            }

            $filtered[] = $line;
        }

        return $filtered;
    }

    /**
     * Only the fields a set filter needs are read from the line.
     */
    private function lineMatchesFilters(string $line, KeyFilters $filters, int $exp, int $now): bool {
        $values = $filters->values;
        $meta = ['ttl' => $exp === -1 ? -1 : $exp - $now];

        if ($values['idle'] > 0) {
            $last_access = $this->lineField($line, 'la') ?? 0;

            if ($last_access === 0) {
                return false;
            }

            $meta['idle'] = $now - $last_access;
        }

        if ($values['minsize'] > 0 || $values['maxsize'] > 0) {
            $meta['size'] = $this->lineField($line, 'size') ?? 0;
        }

        if ($values['nf']) {
            $meta['read'] = !str_contains($line, ' fetch=no');
        }

        return $filters->matches($meta);
    }

    /**
     * A numeric field of a metadump line, faster than parseLine() for one field of every key.
     */
    private function lineField(string $line, string $name): ?int {
        $position = strpos($line, ' '.$name.'=');

        return $position === false ? null : (int) substr($line, $position + strlen($name) + 2, 20);
    }

    /**
     * Delete every key that matches the search and filters.
     *
     * @throws MemcachedException
     */
    private function deleteMatchingKeys(): string {
        if (!$this->keyFilters()->narrowed()) {
            return Helpers::alert('Set a search or a filter first, use "Delete all" to remove every key.', 'error');
        }

        $lines = $this->getAllKeys();

        if ($this->filter_error !== null) {
            return Helpers::alert($this->filter_error, 'error');
        }

        $keys = (static function () use ($lines) {
            foreach ($lines as $line) {
                $space = strpos($line, ' ');
                yield urldecode(substr($line, 4, ($space === false ? strlen($line) : $space) - 4));
            }
        })();

        $deleted = $this->memcached->deleteMany($keys);

        return Helpers::alert(sprintf('%s matching %s been deleted.', Format::number($deleted), $deleted === 1 ? 'key has' : 'keys have'), 'success');
    }

    /**
     * The server clock may differ from the PHP one, times shown in the browser are shifted by the difference.
     *
     * @throws MemcachedException
     */
    private function clockOffset(): int {
        return time() - $this->memcached->serverTime();
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MemcachedException
     */
    public function keysTableView(array $raw_lines): array {
        $formatted_keys = [];
        $now = $this->memcached->serverTime();
        $offset = $this->clockOffset();

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);
            $key = urldecode((string) $key_data['key']);

            $formatted_keys[] = [
                'key'  => $key,
                'info' => [
                    'link_title'           => $key,
                    'bytes_size'           => $key_data['size'] ?? 0,
                    'timediff_last_access' => $this->localTime((int) ($key_data['la'] ?? 0), $offset),
                    'ttl'                  => $this->remainingTtl((int) ($key_data['exp'] ?? -1), $now),
                ],
            ];
        }

        return Helpers::sortKeys($formatted_keys);
    }

    /**
     * @param array<int, string> $raw_lines
     *
     * @return array<int|string, mixed>
     *
     * @throws MemcachedException
     */
    public function keysTreeView(array $raw_lines): array {
        $separator = $this->servers[$this->current_server]['separator'] ?? ':';

        $this->template->addGlobal('separator', $separator);

        $now = $this->memcached->serverTime();
        $offset = $this->clockOffset();

        $keys = [];

        foreach ($raw_lines as $line) {
            $key_data = $this->memcached->parseLine($line);

            if (!isset($key_data['key'])) {
                continue;
            }

            $keys[] = [
                'key'  => urldecode((string) $key_data['key']),
                'info' => [
                    'bytes_size'           => $key_data['size'] ?? 0,
                    'timediff_last_access' => $this->localTime((int) ($key_data['la'] ?? 0), $offset),
                    'ttl'                  => $this->remainingTtl((int) ($key_data['exp'] ?? -1), $now),
                ],
            ];
        }

        return Helpers::keysTree($keys, $separator);
    }

    private function remainingTtl(int $exp, int $now): int|string {
        return $exp === -1 ? 'Doesn\'t expire' : max(0, $exp - $now);
    }

    private function localTime(int $server_time, int $offset): int {
        return $server_time > 0 ? $server_time + $offset : 0;
    }
}
