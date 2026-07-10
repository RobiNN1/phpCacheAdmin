<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards;

use Exception;
use JsonException;
use RobiNN\Pca\Config;
use RobiNN\Pca\Helpers;

trait ConsoleHistoryTrait {
    /**
     * @param array<string, mixed> $data
     */
    private function consoleJson(array $data): string {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            return 'JSON error. '.$e->getMessage();
        }
    }

    private function consoleHistoryFile(): string {
        $dir = Config::get('tmpdir', __DIR__.'/../../tmp').'/console';
        $hash = md5(Helpers::getServerTitle($this->servers[$this->current_server]).Config::get('hash', 'pca'));

        return $dir.'/'.$this->dashboardInfo()['key'].'_history_'.$hash.'.json';
    }

    /**
     * @return array<int, string>
     *
     * @throws JsonException
     */
    private function getConsoleHistory(): array {
        $file = $this->consoleHistoryFile();

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? array_values(array_filter($data, is_string(...))) : [];
    }

    /**
     * @throws JsonException
     */
    private function storeConsoleCommand(string $command): void {
        if ($command === '') {
            return;
        }

        $history = $this->getConsoleHistory();

        if (end($history) === $command) {
            return;
        }

        $history[] = $command;

        $consolehistory = 100;

        if (count($history) > $consolehistory) {
            $history = array_slice($history, -$consolehistory);
        }

        $file = $this->consoleHistoryFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        try {
            file_put_contents($file, json_encode(array_values($history), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        } catch (Exception) {
        }
    }
}
