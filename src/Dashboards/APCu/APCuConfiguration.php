<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

trait APCuConfiguration {
    /**
     * Directive descriptions sourced from the PHP manual (https://www.php.net/manual/en/apcu.configuration.php).
     *
     * @return array<string, string>
     */
    private function configDescriptions(): array {
        return [
            'apc.enabled'          => 'APCu can be disabled via this directive. It is mainly useful when APCu is statically compiled into PHP, as there is no other way to disable it then.',
            'apc.enable_cli'       => 'Enables APCu for the CLI version of PHP. This is mostly useful for testing and debugging; enabling it for production is not recommended.',
            'apc.shm_segments'     => 'The number of shared memory segments to allocate for the cache.',
            'apc.shm_size'         => 'The size of each shared memory segment, with an M/G suffix (e.g. 32M). Some systems limit the size of a single segment.',
            'apc.entries_hint'     => 'A hint about the number of distinct variables that may be stored. Set to 0 to guess automatically.',
            'apc.gc_ttl'           => 'The number of seconds a cache entry may remain on the garbage-collection list. Acts as a failsafe if a process dies while holding a reference to a cached entry.',
            'apc.ttl'              => 'The number of seconds a cache entry is allowed to idle before it may be removed to make room for a new entry. 0 means entries are only removed when memory is needed.',
            'apc.serializer'       => 'Selects the serializer used for stored values. Default is the built-in "php" serializer; "igbinary" is available when compiled in.',
            'apc.mmap_file_mask'   => 'The mktemp-style file mask passed to the mmap module, determining whether the mmap\'ed region is file-backed or backed by POSIX shared memory.',
            'apc.slam_defense'     => 'On busy servers this reduces the chance of several processes caching the same entry at once (a cache "slam") when it is created or expires.',
            'apc.preload_path'     => 'Optional path to a directory of files to load into the cache on startup.',
            'apc.coredump_unmap'   => 'Enables handling of signals such as SIGSEGV that write core files. When received, APCu unmaps the shared memory segment to exclude it from the core dump.',
            'apc.use_request_time' => 'Uses the SAPI request start time for TTL calculations instead of the wall-clock time on each access.',
        ];
    }
}
