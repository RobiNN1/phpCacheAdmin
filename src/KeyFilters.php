<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

/**
 * Search and filters of a key list, read from the URL and shown by partials/key_filters.twig.
 *
 * A dashboard names the filters it supports, the others keep their defaults and are not shown.
 */
class KeyFilters {
    public const MATCH_MODES = ['contains' => 'Contains', 'prefix' => 'Starts with', 'regex' => 'Regex'];

    /**
     * Every filter with its default value.
     *
     * nf - never read, noexp - without expiry, expin - expires within (s), idle - unused for over (s),
     * minsize/maxsize - bytes, type - the type of the key, e.g. a Redis type or "dir" for Realpath.
     *
     * @var array<string, bool|int|string>
     */
    private const DEFAULTS = [
        'nf'      => false,
        'noexp'   => false,
        'expin'   => 0,
        'idle'    => 0,
        'minsize' => 0,
        'maxsize' => 0,
        'type'    => '',
    ];

    public ?string $error = null;

    /**
     * @var array{s: string, match: string, nf: bool, noexp: bool, expin: int, idle: int, minsize: int, maxsize: int, type: string}
     */
    public readonly array $values;

    /**
     * @param array<int, string>    $supported Filters the list supports, keys of DEFAULTS.
     * @param array<string, string> $types     Options of the type filter, value => label.
     */
    public function __construct(private readonly array $supported, private readonly array $types = []) {
        $match = (string) Http::get('match', 'contains');
        $values = [
            's'     => (string) Http::get('s', '', true),
            'match' => array_key_exists($match, self::MATCH_MODES) ? $match : 'contains',
        ];

        foreach (self::DEFAULTS as $name => $default) {
            $values[$name] = match (true) {
                !in_array($name, $this->supported, true) => $default,
                is_bool($default) => in_array(Http::get($name, ''), ['1', 'on'], true),
                is_int($default) => max(0, Http::get($name, 0)),
                default => array_key_exists((string) Http::get($name, ''), $this->types) ? (string) Http::get($name, '') : '',
            };
        }

        /** @var array{s: string, match: string, nf: bool, noexp: bool, expin: int, idle: int, minsize: int, maxsize: int, type: string} $values */
        $this->values = $values;
    }

    /**
     * Whether a filter other than the search is set.
     */
    public function active(): bool {
        foreach (self::DEFAULTS as $name => $default) {
            if ($this->values[$name] !== $default) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the list is narrowed down at all.
     */
    public function narrowed(): bool {
        return $this->values['s'] !== '' || $this->active();
    }

    /**
     * Query parameters of the search and filters, the paginator keeps them.
     *
     * @return array<int, string>
     */
    public function params(): array {
        return ['s', 'match', ...$this->supported];
    }

    /**
     * A PCRE pattern for the search, null without one. An invalid regex sets the error and matches nothing.
     */
    public function pattern(): ?string {
        $search = $this->values['s'];

        if ($search === '') {
            return null;
        }

        $pattern = match ($this->values['match']) {
            'prefix' => '/^'.preg_quote($search, '/').'/i',
            'regex' => '~'.str_replace('~', '\~', $search).'~',
            default => '/'.preg_quote($search, '/').'/i',
        };

        if (@preg_match($pattern, '') === false) {
            $this->error = 'Invalid regular expression: '.preg_last_error_msg();

            return '/(?!)/'; // never matches
        }

        return $pattern;
    }

    /**
     * @return (callable(string): bool)|null
     */
    public function matcher(): ?callable {
        $pattern = $this->pattern();

        return $pattern === null ? null : static fn (string $key): bool => preg_match($pattern, $key) === 1;
    }

    /**
     * Check the metadata of one key against the filters, a filter whose field is missing does not exclude the key.
     *
     * @param array{ttl?: int, idle?: int, size?: int, read?: bool, type?: string} $meta ttl: remaining seconds, -1 never expires; idle: seconds since the last use.
     */
    public function matches(array $meta): bool {
        $values = $this->values;
        $ttl = $meta['ttl'] ?? null;

        if ($ttl !== null) {
            if ($values['noexp'] && $ttl !== -1) {
                return false;
            }

            if ($values['expin'] > 0 && ($ttl === -1 || $ttl > $values['expin'])) {
                return false;
            }
        }

        if ($values['idle'] > 0 && isset($meta['idle']) && $meta['idle'] <= $values['idle']) {
            return false;
        }

        if (isset($meta['size']) && ($meta['size'] < $values['minsize'] || ($values['maxsize'] > 0 && $meta['size'] > $values['maxsize']))) {
            return false;
        }

        if ($values['nf'] && ($meta['read'] ?? false)) {
            return false;
        }

        return !($values['type'] !== '' && isset($meta['type']) && $meta['type'] !== $values['type']);
    }

    /**
     * Data for partials/key_filters.twig.
     *
     * @param array<string, string> $labels  Labels of the filters, e.g. ['nf' => 'Never hit', 'minsize' => 'Memory (bytes)'].
     * @param string|null           $delete  Label of the button that removes the matching keys, null hides it.
     * @param string                $noun    What the list holds, e.g. "script", the plural adds an "s".
     * @param bool                  $partial The list stopped at a limit (Redis SCAN), there can be more matching keys than shown.
     *
     * @return array<string, mixed>
     */
    public function view(int $matching, array $labels = [], ?string $delete = 'Delete', string $note = '', string $noun = 'key', bool $partial = false): array {
        $keep = ['dashboard', 'server', 'db', 'tab', 'view', 'sortdir', 'sortcol', 'pp', 'ignore'];

        return [
            'values'    => $this->values,
            'supported' => array_fill_keys($this->supported, true),
            'labels'    => $labels + [
                    'nf'      => 'Never read',
                    'noexp'   => 'Without expiry',
                    'expin'   => 'Expires within (s)',
                    'idle'    => 'Unused for over (s)',
                    'minsize' => 'Size (bytes)',
                    'type'    => 'Type',
                ],
            'types'     => $this->types,
            'modes'     => self::MATCH_MODES,
            'open'      => $this->values['match'] !== 'contains' || $this->narrowed(),
            'matching'  => $this->narrowed() ? $matching : null,
            'delete'    => $delete,
            'noun'      => $noun,
            'partial'   => $partial,
            'note'      => $note,
            'query'     => array_filter(array_intersect_key($_GET, array_flip($keep)), is_string(...)),
        ];
    }
}
