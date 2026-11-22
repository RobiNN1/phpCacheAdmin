<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

use APCUIterator;
use RobiNN\Pca\Config;
use RobiNN\Pca\Csrf;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Paginator;

trait APCuTrait {
    use APCuPanels;
    use APCuAnalysis;
    use APCuHealth;
    use APCuConfiguration;
    use APCuKeyView;
    use APCuKeysList;

    public function separator(): string {
        return (string) Config::get('apcu.separator', ':');
    }

    /**
     * @var array<string, string>
     */
    private array $tabs = [
        'keys'     => 'Keys',
        'analysis' => 'Analysis',
        'health'   => 'Health',
        'moreinfo' => 'More info',
    ];

    private function currentTab(): string {
        $tab = Http::get('tab', '');

        return array_key_exists($tab, $this->tabs) ? $tab : (string) array_key_first($this->tabs);
    }

    /**
     * Request the keys only for the tabs that show them.
     *
     * @return array<int, string>
     */
    private function keysFlag(): array {
        return in_array($this->currentTab(), ['keys', 'analysis'], true) ? ['keys'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheInfo(): array {
        if ($this->remote === null) {
            return (array) apcu_cache_info(true);
        }

        return (array) ($this->remote->fetched('apcu')['cache_info'] ?? []);
    }

    /**
     * Including the block lists, for the fragmentation and the memory map.
     *
     * @return array<string, mixed>
     */
    private function smaInfo(): array {
        if ($this->remote === null) {
            return apcu_sma_info();
        }

        return (array) ($this->remote->fetched('apcu')['sma_info'] ?? []);
    }

    /**
     * Entries with the fields of the key list, local or from the remote server.
     *
     * @param string|null $pattern A PCRE pattern the keys must match, matched by APCu itself on this server.
     *
     * @return APCUIterator|array<int, array<string, mixed>>
     */
    public function entries(?string $pattern = null): APCUIterator|array {
        if ($this->remote === null) {
            $fields = APC_ITER_KEY | APC_ITER_TTL | APC_ITER_MEM_SIZE | APC_ITER_NUM_HITS | APC_ITER_ATIME | APC_ITER_CTIME;

            // Larger chunks than the default 100 lock the cache less often.
            return new APCUIterator($pattern, $fields, 1000, APC_LIST_ACTIVE);
        }

        $entries = (array) ($this->remote->fetched('apcu')['keys'] ?? []);

        if ($pattern === null) {
            return $entries;
        }

        return array_values(array_filter($entries, static fn (array $item): bool => preg_match($pattern, (string) ($item['key'] ?? '')) === 1));
    }

    public function apcuVersion(): string {
        return $this->remote !== null ? (string) ($this->remote->fetched('apcu')['version'] ?? '') : (string) phpversion('apcu');
    }

    /**
     * @return array<string, mixed>
     */
    private function moreinfoTab(): array {
        $info = $this->cacheInfo();

        foreach ($this->smaInfo() as $mem_name => $mem_value) {
            if (!is_array($mem_value)) {
                $info['memory'][$mem_name] = $mem_value;
            }
        }

        $info += $this->remote !== null ? ['ini_config' => (array) ($this->remote->fetched('apcu')['ini'] ?? [])] : Helpers::getExtIniInfo('apcu');

        return [
            'array'        => Helpers::convertTypesToString($info),
            'descriptions' => $this->configDescriptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function keysTab(): array {
        if (isset($_POST['submit_import_key']) && !$this->isRemote()) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                Helpers::import(
                    static fn (string $key): bool => apcu_exists($key),
                    static function (string $key, string $value, int $ttl): bool {
                        return apcu_store($key, unserialize(base64_decode($value), ['allowed_classes' => false]), $ttl);
                    }
                );
            } else {
                echo Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        Helpers::importResultAlert();

        if (isset($_POST['delete_matching']) && !$this->isRemote()) {
            if (Csrf::validateToken(Http::post('csrf_token', ''))) {
                $this->deleteMatchingKeys();
            } else {
                Helpers::alert('Invalid CSRF token.', 'error');
            }
        }

        $keys = $this->getAllKeys();

        if ($this->filter_error !== null) {
            Helpers::alert($this->filter_error, 'error');
        }

        if (isset($_GET['export_btn']) && !$this->isRemote()) {
            $export_keys = array_map(static fn (array $item): array => [
                'key'  => $item['key'],
                'info' => ['ttl' => (int) ($item['ttl'] ?? 0)],
            ], $keys);

            Helpers::export($export_keys, 'apcu_backup', static fn (string $key): string => base64_encode(serialize(apcu_fetch($key))));
        }

        $keys = Helpers::sortBeforePaginate($keys, [
            'link_title'         => static fn (array $item): string => (string) ($item['key'] ?? ''),
            'bytes_size'         => static fn (array $item): int => (int) ($item['mem_size'] ?? 0),
            'number_hits'        => static fn (array $item): int => (int) ($item['num_hits'] ?? 0),
            'timediff_last_used' => static fn (array $item): int => (int) ($item['access_time'] ?? 0),
            'time_created'       => static fn (array $item): int => (int) ($item['creation_time'] ?? 0),
            'ttl'                => static fn (array $item): int => (int) ($item['ttl'] ?? 0),
        ]);

        $filters = $this->keyFilters();
        $matching = count($keys);
        $paginator = new Paginator($keys, [['pp', 'view', ...$filters->params()], ['p' => '']]);
        $paginated_keys = $paginator->getPaginated();

        if (Http::get('view', Config::get('listview', 'table')) === 'tree') {
            $keys_to_display = $this->keysTreeView($paginated_keys);
        } else {
            $keys_to_display = $this->keysTableView($paginated_keys);
        }

        unset($keys, $paginated_keys);

        $info = $this->cacheInfo();

        return [
            'keys'        => $keys_to_display,
            'all_keys'    => (int) $info['num_entries'],
            'paginator'   => $paginator->render(),
            'view_key'    => $this->isRemote() ? null : Http::queryString([], ['view' => 'key', 'key' => '__key__']),
            'is_remote'   => $this->isRemote(),
            'key_filters' => $filters->view($matching, ['nf' => 'Never hit'], $this->isRemote() ? null : 'Delete'),
        ];
    }

    private function mainDashboard(): string {
        $tab = $this->currentTab();

        $tab_data = match ($tab) {
            'keys' => $this->keysTab(),
            'analysis' => $this->analysisTab(),
            'health' => ['data' => $this->healthTab(), 'tpl' => 'partials/health'],
            'moreinfo' => ['data' => $this->moreinfoTab(), 'tpl' => 'partials/info_table'],
            default => [],
        };

        $tpl = $tab_data['tpl'] ?? 'dashboards/apcu/'.$tab;
        $data = $tab_data['data'] ?? $tab_data;

        if (isset($data['tab_error'])) {
            return htmlspecialchars((string) $data['tab_error']);
        }

        return $this->template->render($tpl, $data);
    }
}
