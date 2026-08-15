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
use RobiNN\Pca\Http;

trait ConsoleTrait {
    /**
     * The name to refuse, which is the subcommand when only that one is blocked, or null when it is allowed.
     *
     * @param array<int, string> $blocked Command names, or "COMMAND SUBCOMMAND" to block a single subcommand.
     * @param array<int, string> $args    The command as it was typed, split into tokens.
     */
    private function blockedCommand(array $blocked, array $args): ?string {
        $command = ($args[0] ?? '');
        $subcommand = isset($args[1]) ? $command.' '.$args[1] : '';

        if ($subcommand !== '' && in_array(strtoupper($subcommand), $blocked, true)) {
            return $subcommand;
        }

        return in_array(strtoupper($command), $blocked, true) ? $command : null;
    }

    private function consoleEnabled(): bool {
        return (bool) Config::get('console', true);
    }

    /**
     * Extra commands to refuse, from the "blockedcommands" option of the given config group.
     * A "COMMAND SUBCOMMAND" entry blocks only that subcommand.
     *
     * @return array<int, string>
     */
    private function configuredBlockedCommands(string $group): array {
        $commands = array_filter((array) Config::get($group.'.blockedcommands', []), is_string(...));

        return array_map(static fn (string $command): string => strtoupper(trim($command)), array_values($commands));
    }

    /**
     * Point at the tab that shows the same thing as the command but in UI.
     *
     * @param array<string, string> $tabs Command => tab key.
     * @param array<int, string>    $args The command as it was typed, split into tokens.
     *
     * @return array<string, array<string, string>>
     */
    private function consoleTabHint(array $tabs, array $args, string $label): array {
        $command = strtoupper($args[0] ?? '');
        $tab = $tabs[$command.' '.strtoupper($args[1] ?? '')] ?? $tabs[$command] ?? null;

        if ($tab === null) {
            return [];
        }

        return ['tab' => [
            'url'   => Http::queryString([], ['tab' => $tab]),
            'label' => sprintf($label, $this->tabs[$tab] ?? $tab),
        ]];
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

        if (!Helpers::makeDir(dirname($file))) {
            return;
        }

        try {
            file_put_contents($file, json_encode(array_values($history), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        } catch (Exception) {
        }
    }
}
