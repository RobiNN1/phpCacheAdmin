<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

class ReadonlyMode {
    public const MESSAGE = 'Read-only mode is enabled, this action is not allowed.';

    /**
     * @var array<int, string>
     */
    private const GET_ACTIONS = ['delete', 'deleteall', 'form'];

    /**
     * Slowlog and latency reset/config and OPcache warmup are not blocked, they only touch diagnostics.
     *
     * @var array<int, string>
     */
    private const POST_ACTIONS = [
        'delete', 'deletesub', 'submit', 'submit_import_key', 'kill_client', 'publish', 'command', 'delete_matching', 'change_ttl',
    ];

    /**
     * Read-only for every server, or only for the given one with its 'readonly' option.
     *
     * @param array<string, mixed>|null $server
     */
    public static function enabled(?array $server = null): bool {
        return Config::get('readonly', false) || ($server['readonly'] ?? false);
    }

    /**
     * Whether the actions of the current dashboard are blocked, a dashboard opts out with 'readonly' => false.
     *
     * @param array<string, mixed>|null $server
     */
    public static function blocks(bool $applies = true, ?array $server = null): bool {
        return self::enabled($server) && $applies;
    }

    /**
     * Block everything that would change data. The UI hides all of these actions.
     *
     * @param array<string, mixed>|null $server
     */
    public static function guard(bool $applies = true, ?array $server = null): ?string {
        if (!self::blocks($applies, $server)) {
            return null;
        }

        $blocked = self::stripActions();

        if ($blocked === []) {
            return null;
        }

        if (!isset($_GET['ajax'])) {
            Helpers::alert(self::MESSAGE, 'error');

            return null;
        }

        if (isset($_GET['console']) || isset($_GET['pubsub'])) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }

            return Helpers::ajaxJson(['error' => self::MESSAGE]);
        }

        return Helpers::alert(self::MESSAGE, 'error');
    }

    /**
     * @return array<int, string>
     */
    private static function stripActions(): array {
        $blocked = [];

        foreach (self::GET_ACTIONS as $action) {
            if (isset($_GET[$action])) {
                unset($_GET[$action]);
                $blocked[] = $action;
            }
        }

        foreach (self::POST_ACTIONS as $action) {
            if (isset($_POST[$action])) {
                unset($_POST[$action]);
                $blocked[] = $action;
            }
        }

        if (isset($_FILES['import'])) {
            unset($_FILES['import']);
            $blocked[] = 'import';
        }

        return $blocked;
    }
}
