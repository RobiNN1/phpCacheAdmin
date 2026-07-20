<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\OPCache;

use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;

trait OPCacheTrait {
    use OPCachePanels;
    use OPCacheHealth;
    use OPCacheScripts;
    use OPCacheWarmup;
    use OPCacheConfiguration;

    private const NOT_AVAILABLE = 'OPcache is not available, it is either disabled (opcache.enable) or restricted (opcache.restrict_api).';

    private const NO_SHARED_MEMORY = 'Statistics cannot be displayed with opcache.file_cache_only enabled.';

    /**
     * @var array<string, string>
     */
    private array $tabs = [
        'scripts'  => 'Scripts',
        'health'   => 'Health',
        'treemap'  => 'Memory map',
        'warmup'   => 'Warmup',
        'moreinfo' => 'More info',
    ];

    /**
     * @return array<string, mixed>
     */
    private function moreinfoTab(): array {
        $status = @opcache_get_status(false);

        if ($status === false) {
            return ['tab_error' => self::NOT_AVAILABLE];
        }

        $directives = opcache_get_configuration()['directives'];
        $formatted = [];

        foreach ($directives as $key => $value) {
            $formatted[$key] = $this->formatDirectiveValue($key, $value);
        }

        $status['ini_config'] = $formatted;

        return [
            'array'        => Helpers::convertTypesToString($status),
            'descriptions' => $this->configDescriptions(),
        ];
    }

    private function mainDashboard(): string {
        $tab = Http::get('tab', '');
        $tab = array_key_exists($tab, $this->tabs) ? $tab : array_key_first($this->tabs);

        $tab_data = match ($tab) {
            'scripts' => $this->scriptsTab(),
            'health' => ['data' => $this->healthTab(), 'tpl' => 'partials/health'],
            'treemap' => $this->treemapTab(),
            'warmup' => $this->warmupTab(),
            'moreinfo' => ['data' => $this->moreinfoTab(), 'tpl' => 'partials/info_table'],
            default => [],
        };

        $tpl = $tab_data['tpl'] ?? 'dashboards/opcache/'.$tab;
        $data = $tab_data['data'] ?? $tab_data;

        return $data['tab_error'] ?? $this->template->render($tpl, $data);
    }
}
