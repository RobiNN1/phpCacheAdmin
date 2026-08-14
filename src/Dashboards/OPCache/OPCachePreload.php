<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

trait OPCachePreload {
    /**
     * @return array<string, mixed>
     */
    private function preloadTab(): array {
        $status = @opcache_get_status(false);

        if ($status === false) {
            return ['tab_error' => self::NOT_AVAILABLE];
        }

        $directives = $this->directives();
        $preload = $status['preload_statistics'] ?? [];

        return [
            'preload'   => [
                'script'    => (string) ($directives['opcache.preload'] ?? ''),
                'user'      => (string) ($directives['opcache.preload_user'] ?? ''),
                'memory'    => (int) ($preload['memory_consumption'] ?? 0),
                'files'     => $this->preloadedFiles(is_array($preload) ? $preload : []),
                'functions' => count((array) ($preload['functions'] ?? [])),
                'classes'   => count((array) ($preload['classes'] ?? [])),
            ],
            'blacklist' => $this->blacklist((string) ($directives['opcache.blacklist_filename'] ?? '')),
        ];
    }

    /**
     * Preloading keeps the compiled files for the whole life of the server, so nothing here can be invalidated.
     *
     * @param array<string, mixed> $preload
     *
     * @return array<int, string>
     */
    public function preloadedFiles(array $preload): array {
        $files = array_map(static fn (mixed $file): string => str_replace('\\', '/', (string) $file), (array) ($preload['scripts'] ?? []));

        sort($files);

        return $files;
    }

    /**
     * The blacklist names the files OPcache never caches. It is only read at startup, so the file can hold
     * patterns that no longer match anything, and OPcache does not report which files it skipped because of it.
     *
     * @return array<string, mixed>
     */
    public function blacklist(string $filename): array {
        $blacklist = ['filename' => $filename, 'patterns' => [], 'unreadable' => []];

        if ($filename === '') {
            return $blacklist;
        }

        // The directive itself may be a glob, e.g., /etc/php/opcache-blacklist.d/*.txt
        $files = glob($filename) ?: [$filename];

        foreach ($files as $file) {
            if (!is_readable($file)) {
                $blacklist['unreadable'][] = $file;

                continue;
            }

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);

                if ($line !== '' && !str_starts_with($line, ';')) {
                    $blacklist['patterns'][] = $line;
                }
            }
        }

        return $blacklist;
    }

    /**
     * The tab has nothing to show unless one of the two is configured.
     */
    private function preloadIsConfigured(): bool {
        $directives = $this->directives();

        return ($directives['opcache.preload'] ?? '') !== '' || ($directives['opcache.blacklist_filename'] ?? '') !== '';
    }

    /**
     * Empty when OPcache is disabled or restricted (opcache.restrict_api).
     *
     * @return array<string, mixed>
     */
    private function directives(): array {
        $configuration = self::check() ? @opcache_get_configuration() : false;

        return is_array($configuration) ? $configuration['directives'] : [];
    }
}
