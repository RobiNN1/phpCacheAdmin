#!/usr/bin/env php
<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 *
 * Regenerates assets/redis-commands.json (command hints for the Redis console)
 * from the connected server's `COMMAND DOCS` output.
 *
 * Usage:
 *   php bin/generate-redis-commands.php [host] [port]
 *
 * Requires predis/predis (phpredis mangles the argument "flags" set in COMMAND DOCS).
 */

declare(strict_types=1);

use Predis\Client;

require __DIR__.'/../vendor/autoload.php';

if (!class_exists(Client::class)) {
    fwrite(STDERR, "predis/predis is required. Run: composer require --dev predis/predis\n");
    exit(1);
}

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 6379);
$output = __DIR__.'/../assets/redis-commands.json';

/**
 * Convert a flat RESP reply ([key, value, key, value, ...]) into an associative array.
 *
 * @param array<int, mixed> $flat
 *
 * @return array<string, mixed>
 */
function flat_to_map(array $flat): array {
    $map = [];

    for ($i = 0, $loops_max = count($flat); $i + 1 < $loops_max; $i += 2) {
        $map[(string) $flat[$i]] = $flat[$i + 1];
    }

    return $map;
}

/**
 * Build a redis-cli style syntax string for a single argument (flat RESP form).
 *
 * @param array<int, mixed> $flat_arg
 */
function arg_to_str(array $flat_arg): string {
    $arg = flat_to_map($flat_arg);
    $type = (string) ($arg['type'] ?? '');
    $flags = is_array($arg['flags'] ?? null) ? $arg['flags'] : [];
    $children = is_array($arg['arguments'] ?? null) ? $arg['arguments'] : [];

    if ($type === 'oneof') {
        $string = implode(' | ', array_map(arg_to_str(...), $children));
    } elseif ($type === 'block') {
        $string = implode(' ', array_map(arg_to_str(...), $children));
    } else {
        $label = (string) ($arg['display_text'] ?? $arg['name'] ?? '');
        $token = $arg['token'] ?? null;

        if ($token === null || $token === '') {
            $string = $label;
        } elseif ($type === 'pure-token') {
            $string = (string) $token;
        } else {
            $string = $token.' '.$label;
        }
    }

    if (in_array('multiple', $flags, true)) {
        $string .= ' ['.$string.' ...]';
    }

    if (in_array('optional', $flags, true)) {
        $string = '['.$string.']';
    }

    return $string;
}

/**
 * @param array<string, mixed> $doc
 */
function args_hint(array $doc): string {
    $arguments = is_array($doc['arguments'] ?? null) ? $doc['arguments'] : [];

    return trim(implode(' ', array_map(arg_to_str(...), $arguments)));
}

/**
 * @param array<string, mixed> $doc
 *
 * @return array<string, string>
 */
function entry(array $doc): array {
    $entry = [];

    if (($hint = args_hint($doc)) !== '') {
        $entry['args'] = $hint;
    }

    if (isset($doc['summary']) && $doc['summary'] !== '') {
        $entry['summary'] = (string) $doc['summary'];
    }

    return $entry;
}

$client = new Client(['host' => $host, 'port' => $port]);

try {
    $client->connect();
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("Could not connect to %s:%d - %s\n", $host, $port, $e->getMessage()));
    exit(1);
}

$docs = $client->executeRaw(['COMMAND', 'DOCS']);

if (!is_array($docs)) {
    fwrite(STDERR, "Unexpected COMMAND DOCS reply.\n");
    exit(1);
}

$commands = [];

foreach (flat_to_map($docs) as $name => $flat_doc) {
    if (!is_array($flat_doc)) {
        continue;
    }

    $doc = flat_to_map($flat_doc);

    if (isset($doc['subcommands']) && is_array($doc['subcommands'])) {
        $sub_names = [];

        foreach (flat_to_map($doc['subcommands']) as $sub_name => $sub_flat) {
            if (!is_array($sub_flat)) {
                continue;
            }

            // "config|get" -> "CONFIG GET"
            $commands[strtoupper(str_replace('|', ' ', $sub_name))] = entry(flat_to_map($sub_flat));
            $sub_names[] = strtoupper(explode('|', $sub_name)[1] ?? $sub_name);
        }

        sort($sub_names);

        $container = isset($doc['summary']) ? ['summary' => (string) $doc['summary']] : [];
        $container['args'] = implode(' | ', $sub_names);
        $commands[strtoupper($name)] = $container;
    } else {
        $commands[strtoupper($name)] = entry($doc);
    }
}

ksort($commands);

try {
    $json = json_encode($commands, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'Failed to encode commands: '.$e->getMessage()."\n");
    exit(1);
}

if (file_put_contents($output, $json) === false) {
    fwrite(STDERR, 'Failed to write '.$output."\n");
    exit(1);
}

printf("Wrote %d commands to %s (%d bytes).\n", count($commands), realpath($output), filesize($output));

exit(0);
