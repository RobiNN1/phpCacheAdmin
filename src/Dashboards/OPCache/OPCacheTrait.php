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
    use OPCachePreload;
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
        'preload'  => 'Preload',
        'warmup'   => 'Warmup',
        'moreinfo' => 'More info',
    ];

    private function currentTab(): string {
        $tab = Http::get('tab', '');

        return array_key_exists($tab, $this->tabs) ? $tab : (string) array_key_first($this->tabs);
    }

    /**
     * The list of scripts is the largest part of the response, so it is only requested for the tabs that show it.
     *
     * @return array<int, string>
     */
    private function scriptsFlag(): array {
        return in_array($this->currentTab(), ['scripts', 'treemap'], true) ? ['scripts'] : [];
    }

    /**
     * False when OPcache is disabled or restricted (opcache.restrict_api).
     *
     * @return array<string, mixed>|false
     */
    private function status(bool $scripts = false): array|false {
        if ($this->remote === null) {
            return @opcache_get_status($scripts);
        }

        $status = $this->remote->fetched('opcache')['status'] ?? false;

        if (!is_array($status)) {
            return false;
        }

        if (!$scripts) {
            unset($status['scripts']); // The response may be the one fetched with the scripts.
        }

        return $status;
    }

    /**
     * Empty when OPcache is disabled or restricted (opcache.restrict_api).
     *
     * @return array<string, mixed>
     */
    private function directives(): array {
        if ($this->remote !== null) {
            $configuration = $this->remote->fetched('opcache')['configuration'] ?? false;
        } else {
            $configuration = extension_loaded('Zend OPcache') ? @opcache_get_configuration() : false;
        }

        return is_array($configuration) && is_array($configuration['directives'] ?? null) ? $configuration['directives'] : [];
    }

    public function opcacheVersion(): string {
        return $this->remote !== null ? (string) ($this->remote->fetched('opcache')['version'] ?? '') : (string) phpversion('Zend OPcache');
    }

    /**
     * @return array<string, mixed>
     */
    private function moreinfoTab(): array {
        $status = $this->status();

        if ($status === false) {
            return ['tab_error' => self::NOT_AVAILABLE];
        }

        $directives = $this->directives();
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
        $tab = $this->currentTab();

        $tab_data = match ($tab) {
            'scripts' => $this->scriptsTab(),
            'health' => ['data' => $this->healthTab(), 'tpl' => 'partials/health'],
            'treemap' => $this->treemapTab(),
            'preload' => $this->preloadTab(),
            'warmup' => $this->warmupTab(),
            'moreinfo' => ['data' => $this->moreinfoTab(), 'tpl' => 'partials/info_table'],
            default => [],
        };

        $tpl = $tab_data['tpl'] ?? 'dashboards/opcache/'.$tab;
        $data = $tab_data['data'] ?? $tab_data;

        if (isset($data['tab_error'])) {
            return htmlspecialchars((string) $data['tab_error']);
        }

        return $this->template->render($tpl, $data);
    }
}
