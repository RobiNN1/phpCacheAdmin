<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use Throwable;

trait OPCacheWarmup {
    /**
     * @return array<string, mixed>
     */
    private function warmupTab(): array {
        $status = opcache_get_status(false);

        if ($status === false) {
            return ['tab_error' => 'OPcache is not available, it is either disabled (opcache.enable) or restricted (opcache.restrict_api).'];
        }

        $path = Http::post('warmup_path', '');
        $extensions = Http::post('warmup_ext', 'php');
        $result = [];

        if (isset($_POST['warmup'])) {
            if (!Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::alert('Invalid CSRF token.', 'error');
            } else {
                $result = $this->warmupPath($path, $extensions);

                if (isset($result['error'])) {
                    Helpers::alert((string) $result['error'], 'error');
                    $result = [];
                }
            }
        }

        return [
            'path'   => $path !== '' ? $path : ($_SERVER['DOCUMENT_ROOT'] ?? ''),
            'ext'    => $extensions,
            'result' => $result,
        ];
    }

    /**
     * Compile files into an OPcache. opcache_compile_file() caches the bytecode without executing the file.
     *
     * @return array<string, mixed>
     */
    private function warmupPath(string $path, string $extensions): array {
        $path = trim($path);

        if ($path === '') {
            return ['error' => 'Enter a directory or file path.'];
        }

        $real_path = realpath($path);

        if ($real_path === false || !is_readable($real_path)) {
            return ['error' => 'Path does not exist or is not readable: '.$path];
        }

        $files = $this->findFiles($real_path, $this->parseExtensions($extensions));

        if ($files === []) {
            return ['error' => 'No matching files found in: '.$real_path];
        }

        $compiled = 0;
        $cached = 0;
        $errors = [];

        foreach ($files as $file) {
            if (opcache_is_script_cached($file)) {
                $cached++;
                continue;
            }

            try {
                if (@opcache_compile_file($file)) {
                    $compiled++;
                } else {
                    $errors[] = $file;
                }
            } catch (Throwable $e) {
                $errors[] = $file.' - '.$e->getMessage();
            }
        }

        return [
            'path'     => $real_path,
            'total'    => count($files),
            'compiled' => $compiled,
            'cached'   => $cached,
            'failed'   => count($errors),
            'errors'   => array_slice($errors, 0, 50),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseExtensions(string $extensions): array {
        $parsed = array_filter(array_map(
            static fn (string $extension): string => strtolower(ltrim(trim($extension), '.')),
            explode(',', $extensions)
        ));

        return $parsed !== [] ? array_values($parsed) : ['php'];
    }

    /**
     * @param array<int, string> $extensions
     *
     * @return array<int, string>
     */
    private function findFiles(string $path, array $extensions): array {
        if (is_file($path)) {
            return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true) ? [$path] : [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
