<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\APCu;

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

    /**
     * @var array<string, string>
     */
    private array $tabs = [
        'keys'     => 'Keys',
        'analysis' => 'Analysis',
        'health'   => 'Health',
        'moreinfo' => 'More info',
    ];

    /**
     * @return array<string, mixed>
     */
    private function moreinfoTab(): array {
        $info = (array) apcu_cache_info(true);

        foreach (apcu_sma_info(true) as $mem_name => $mem_value) {
            if (!is_array($mem_value)) {
                $info['memory'][$mem_name] = $mem_value;
            }
        }

        $info += Helpers::getExtIniInfo('apcu');

        return [
            'array'        => Helpers::convertTypesToString($info),
            'descriptions' => $this->configDescriptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function keysTab(): array {
        if (isset($_POST['submit_import_key'])) {
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

        $keys = $this->getAllKeys();

        if (isset($_GET['export_btn'])) {
            $export_keys = array_map(static fn (array $item): array => [
                'key'  => $item['key'],
                'info' => ['ttl' => (int) ($item['ttl'] ?? 0)],
            ], $keys);

            Helpers::export($export_keys, 'apcu_backup', static fn (string $key): string => base64_encode(serialize(apcu_fetch($key))));
        }

        $paginator = new Paginator($keys);
        $paginated_keys = $paginator->getPaginated();

        if (Http::get('view', Config::get('listview', 'table')) === 'tree') {
            $keys_to_display = $this->keysTreeView($paginated_keys);
        } else {
            $keys_to_display = $this->keysTableView($paginated_keys);
        }

        unset($keys, $paginated_keys);

        $info = apcu_cache_info(true);

        return [
            'keys'      => $keys_to_display,
            'all_keys'  => (int) $info['num_entries'],
            'paginator' => $paginator->render(),
            'view_key'  => Http::queryString([], ['view' => 'key', 'key' => '__key__']),
        ];
    }

    private function mainDashboard(): string {
        $tab = Http::get('tab', '');
        $tab = array_key_exists($tab, $this->tabs) ? $tab : array_key_first($this->tabs);

        $tab_data = match ($tab) {
            'keys' => $this->keysTab(),
            'analysis' => $this->analysisTab(),
            'health' => ['data' => $this->healthTab(), 'tpl' => 'partials/health'],
            'moreinfo' => ['data' => $this->moreinfoTab(), 'tpl' => 'partials/info_table'],
            default => [],
        };

        $tpl = $tab_data['tpl'] ?? 'dashboards/apcu/'.$tab;
        $data = $tab_data['data'] ?? $tab_data;

        return $data['tab_error'] ?? $this->template->render($tpl, $data);
    }
}
