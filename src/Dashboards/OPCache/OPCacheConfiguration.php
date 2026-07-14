<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Format;

trait OPCacheConfiguration {
    private function formatDirectiveValue(string $key, mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'On' : 'Off';
        }

        return match ($key) {
            'opcache.memory_consumption', 'opcache.jit_buffer_size' => Format::bytes((int) $value),
            'opcache.interned_strings_buffer' => (int) $value.' MB',
            'opcache.max_wasted_percentage' => Format::number((float) $value * 100, 1).'%',
            'opcache.force_restart_timeout', 'opcache.revalidate_freq', 'opcache.file_update_protection' => (int) $value.'s',
            'opcache.optimization_level' => '0x'.strtoupper(dechex((int) $value)),
            'opcache.jit' => $value === '' ? 'off' : (string) $value,
            default => $value === '' ? '—' : (string) $value,
        };
    }

    /**
     * Directive descriptions sourced from the PHP manual (https://www.php.net/manual/en/opcache.configuration.php).
     *
     * @return array<string, string>
     */
    private function configDescriptions(): array {
        return [
            'opcache.enable'                        => 'Enables the opcode cache. When disabled, code is not optimised or cached. Cannot be enabled at runtime through ini_set(), only disabled.',
            'opcache.enable_cli'                    => 'Enables the opcode cache for the CLI version of PHP.',
            'opcache.memory_consumption'            => 'The size of the shared memory storage used by OPcache. The minimum value is 8 MB.',
            'opcache.interned_strings_buffer'       => 'The amount of memory used to store interned strings, in megabytes.',
            'opcache.max_accelerated_files'         => 'The maximum number of keys (and therefore scripts) in the OPcache hash table. The actual value used is the first prime number bigger than the one configured. Minimum 200, maximum 1000000.',
            'opcache.max_wasted_percentage'         => 'The maximum percentage of wasted memory that is allowed before a restart is scheduled. The maximum value is 50.',
            'opcache.use_cwd'                       => 'If enabled, OPcache appends the current working directory to the script key, eliminating possible collisions between files with the same base name.',
            'opcache.validate_timestamps'           => 'If enabled, OPcache checks for updated scripts every opcache.revalidate_freq seconds. When disabled, you must reset OPcache manually (opcache_reset(), opcache_invalidate() or a web server restart) for filesystem changes to take effect.',
            'opcache.revalidate_freq'               => 'How often to check script timestamps for updates, in seconds. 0 checks for updates on every request.',
            'opcache.revalidate_path'               => 'If disabled, existing cached files using an unresolved include_path are reused, so a file with the same name elsewhere in the include_path is not found.',
            'opcache.save_comments'                 => 'If disabled, documentation comments are discarded to reduce cached code size. Disabling may break frameworks that rely on annotations (Doctrine, PHPUnit, etc.).',
            'opcache.enable_file_override'          => 'When enabled, the cache is checked when file_exists(), is_file() and is_readable() are called. May improve performance but risks stale data if opcache.validate_timestamps is disabled.',
            'opcache.optimization_level'            => 'A bitmask that controls which optimisation passes the optimizer runs when compiling scripts. The default (0x7FFEBFFF) enables all safe passes.',
            'opcache.dups_fix'                      => 'This hack should only be enabled to work around "Cannot redeclare class" errors.',
            'opcache.blacklist_filename'            => 'The location of the OPcache blacklist file: a text file listing scripts that should not be accelerated, one per line. Wildcards and prefixes are allowed; lines starting with a semicolon are comments.',
            'opcache.max_file_size'                 => 'The maximum size of a file that OPcache will cache, in bytes. 0 means all files are cached.',
            'opcache.consistency_checks'            => 'If non-zero, OPcache verifies the cache checksum every N requests. Enable only when debugging, as it impacts performance. Removed in PHP 8.3.',
            'opcache.force_restart_timeout'         => 'How long to wait for a scheduled restart to begin if the cache is not accessed, in seconds. On timeout, OPcache kills the process holding the cache lock to permit a restart.',
            'opcache.error_log'                     => 'OPcache error log. An empty string is treated as stderr, sending logs to the standard error output (usually the web server error log).',
            'opcache.log_verbosity_level'           => 'Log verbosity level. By default only fatal errors (0) and errors (1) are logged. Other levels: warnings (2), info (3), debug (4).',
            'opcache.preferred_memory_model'        => 'The preferred shared memory model. If empty, OPcache chooses the most appropriate one (correct in virtually all cases). Possible values: mmap, shm, posix, win32.',
            'opcache.protect_memory'                => 'Protects shared memory from unexpected writes while executing scripts. Useful for internal debugging only.',
            'opcache.restrict_api'                  => 'Allows calling OPcache API functions only from PHP scripts whose path starts with the given string. An empty string means no restriction.',
            'opcache.mmap_base'                     => 'The base address for the shared memory mapping (Windows only). Use to fix "Unable to reattach to base address" errors.',
            'opcache.file_cache'                    => 'Enables and sets the second-level (file) cache directory. Improves performance when SHM is full, at server restart or after an SHM reset. Empty disables file caching.',
            'opcache.file_cache_only'               => 'Enables or disables opcode caching in shared memory (uses the file cache only).',
            'opcache.file_cache_consistency_checks' => 'Enables or disables checksum validation when a script is loaded from the file cache.',
            'opcache.file_cache_fallback'           => 'Implies opcache.file_cache_only=1 for a process that failed to reattach to shared memory. Windows only.',
            'opcache.file_update_protection'        => 'Prevents caching of files younger than this number of seconds, protecting against caching partially updated files. Set to 0 if all updates are atomic.',
            'opcache.huge_code_pages'               => 'Enables or disables copying of PHP code into HUGE PAGES. Improves performance but requires appropriate OS configuration.',
            'opcache.lockfile_path'                 => 'Absolute path used to store shared lockfiles (*nix only).',
            'opcache.opt_debug_level'               => 'Produces opcode dumps for debugging optimisation stages. 0x10000 dumps opcodes before optimisation; 0x20000 dumps them after.',
            'opcache.validate_permission'           => 'Validates the cached file permissions against the current user.',
            'opcache.validate_root'                 => 'Prevents name collisions in chroot\'ed environments. Should be enabled in all chroot\'ed environments to prevent access to files outside the chroot.',
            'opcache.preload'                       => 'A PHP script compiled and executed at server start-up that may preload other files. Everything defined in these files (functions, classes, etc.) is available to every request until the server shuts down.',
            'opcache.preload_user'                  => 'Runs preloading as the specified system user. Useful for servers that start as root before switching to an unprivileged user. Preloading as root is not allowed unless this is explicitly set to root.',
            'opcache.cache_id'                      => 'On Windows, processes running the same SAPI under the same user account with the same cache ID share a single OPcache instance.',
            'opcache.record_warnings'               => 'If enabled, OPcache records compile-time warnings and replays them on the next include, even when served from cache.',
            'opcache.jit'                           => 'JIT mode. Common values: disable, off, tracing (on) or function. Advanced usage accepts a 4-digit CRTO integer where each digit controls a JIT flag.',
            'opcache.jit_buffer_size'               => 'The amount of shared memory reserved for compiled JIT code. A zero value disables the JIT.',
            'opcache.jit_debug'                     => 'A bitmask specifying which JIT debug output to enable. See zend_jit.h for possible values.',
            'opcache.jit_prof_threshold'            => 'In "profile on first request" mode, the threshold above which a function is considered hot (calls to the function divided by all calls).',
            'opcache.jit_max_root_traces'           => 'Maximum number of root traces. Setting this to 0 disables JIT trace compilation.',
            'opcache.jit_max_side_traces'           => 'Maximum number of side traces per root trace.',
            'opcache.jit_max_exit_counters'         => 'Maximum number of side trace exit counters, limiting the total number of side traces across all root traces.',
            'opcache.jit_hot_loop'                  => 'After how many iterations a loop is considered hot.',
            'opcache.jit_hot_func'                  => 'After how many calls a function is considered hot.',
            'opcache.jit_hot_return'                => 'After how many returns a return is considered hot.',
            'opcache.jit_hot_side_exit'             => 'After how many exits a side exit is considered hot.',
            'opcache.jit_blacklist_root_trace'      => 'Maximum number of attempts to compile a root trace before it is blacklisted.',
            'opcache.jit_blacklist_side_trace'      => 'Maximum number of attempts to compile a side trace before it is blacklisted.',
            'opcache.jit_max_loop_unrolls'          => 'Maximum number of attempts to unroll a loop in a side trace.',
            'opcache.jit_max_recursive_calls'       => 'Maximum number of unrolled recursive call loops.',
            'opcache.jit_max_recursive_returns'     => 'Maximum number of unrolled recursive return loops.',
            'opcache.jit_max_polymorphic_calls'     => 'Maximum number of attempts to inline a polymorphic call. Calls above this limit are treated as megamorphic and not inlined.',
        ];
    }
}
