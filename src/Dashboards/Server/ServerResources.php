<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca\Dashboards\Server;

use JsonException;
use RobiNN\Pca\Format;

trait ServerResources {
    /**
     * @return array{title: string, data: array<int|string, mixed>}
     */
    private function resourcesPanel(): array {
        $stats = $this->cachedStats();
        $data = [];

        $cpu = $stats['cpu'] ?? null;

        if (is_int($cpu)) {
            $data[] = ['CPU usage', $cpu.'%', $cpu];
        }

        $memory = $stats['memory'] ?? null;

        if (is_array($memory) && (int) $memory['total'] > 0) {
            $usage = round(((int) $memory['used'] / (int) $memory['total']) * 100, 2);
            $data[] = ['RAM', Format::bytes((int) $memory['used']).' / '.Format::bytes((int) $memory['total'], 0).' ('.$usage.'%)', $usage];
            $data['RAM free'] = Format::bytes((int) $memory['free']);
        }

        $disk = $stats['disk'] ?? null;

        if (is_array($disk) && (int) $disk['total'] > 0) {
            $usage = round(((int) $disk['used'] / (int) $disk['total']) * 100, 2);
            $data[] = ['Disk', Format::bytes((int) $disk['used']).' / '.Format::bytes((int) $disk['total'], 0).' ('.$usage.'%)', $usage];
            $data['Disk free'] = Format::bytes((int) $disk['free']);
        }

        return [
            'title' => 'System resources',
            'data'  => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedStats(): array {
        $file = sys_get_temp_dir().'/pca_server_resources_'.md5(__DIR__).'.json';

        try {

            if (is_readable($file) && (time() - (int) filemtime($file)) < 5) {
                $cached = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                if (is_array($cached)) {
                    return $cached;
                }
            }

            $stats = [
                'cpu'    => $this->cpuUsage(),
                'memory' => $this->systemMemory(),
                'disk'   => $this->diskUsage(),
            ];

            $json = json_encode($stats, JSON_THROW_ON_ERROR);

            if ($json !== false) {
                @file_put_contents($file, $json);
            }

            return $stats;
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @return array{total: int, used: int, free: int}|null
     */
    private function diskUsage(): ?array {
        $total = @disk_total_space(__DIR__);
        $free = @disk_free_space(__DIR__);

        if (!is_float($total) || $total <= 0.0 || !is_float($free)) {
            return null;
        }

        return ['total' => (int) $total, 'used' => (int) ($total - $free), 'free' => (int) $free];
    }

    private function cpuUsage(): ?int {
        if (PHP_OS_FAMILY === 'Linux') {
            return $this->linuxCpuUsage();
        }

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'top -l 1 | grep -E "^CPU" | tail -1 | awk \'{ print $3 + $5 }\'',
            'Windows' => 'wmic cpu get loadpercentage | more +1',
            'BSD' => 'top -b -d 2 | grep "CPU: " | tail -1 | awk \'{print $10}\' | grep -Eo \'[0-9]+\.[0-9]+\' | awk \'{ print 100 - $1 }\'',
            default => null,
        };

        if ($command === null) {
            return null;
        }

        $output = $this->shell($command);

        return $output === null ? null : min(100, max(0, (int) round((float) $output)));
    }

    private function linuxCpuUsage(): ?int {
        $first = $this->readProcStat();

        if ($first === null) {
            return null;
        }

        usleep(200000);
        $second = $this->readProcStat();

        if ($second === null) {
            return null;
        }

        $total = $second['total'] - $first['total'];
        $idle = $second['idle'] - $first['idle'];

        if ($total <= 0) {
            return null;
        }

        return min(100, max(0, (int) round((1 - $idle / $total) * 100)));
    }

    /**
     * @return array{total: int, idle: int}|null
     */
    private function readProcStat(): ?array {
        $stat = @file_get_contents('/proc/stat');

        if ($stat === false || preg_match('/^cpu\s+(.+)$/m', $stat, $matches) !== 1) {
            return null;
        }

        $values = array_map(intval(...), preg_split('/\s+/', trim($matches[1])) ?: []);

        return [
            'total' => array_sum($values),
            'idle'  => ($values[3] ?? 0) + ($values[4] ?? 0),
        ];
    }

    /**
     * @return array{total: int, used: int, free: int}|null
     */
    private function systemMemory(): ?array {
        $total = 0;
        $used = 0;

        switch (PHP_OS_FAMILY) {
            case 'Darwin':
                $total = $this->intShell('sysctl -n hw.memsize');
                $pagesize = $this->intShell('pagesize');
                $vm_stat = (string) $this->shell('vm_stat');

                // Match Activity Monitor's "Memory Used" = App + Wired + Compressed. Free/inactive/file-backed
                // pages are reclaimable cache and must not be counted, otherwise usage looks near 100%.
                $used_pages = $this->vmStatPages($vm_stat, 'Anonymous pages')
                    - $this->vmStatPages($vm_stat, 'Pages purgeable')
                    + $this->vmStatPages($vm_stat, 'Pages wired down')
                    + $this->vmStatPages($vm_stat, 'Pages occupied by compressor');
                $used = $used_pages * $pagesize;
                break;
            case 'Linux':
                $meminfo = (string) @file_get_contents('/proc/meminfo');
                $total = $this->meminfoBytes($meminfo, 'MemTotal');
                $available = $this->meminfoBytes($meminfo, 'MemAvailable');
                $used = $total - $available;
                break;
            case 'BSD':
                $total = $this->intShell('sysctl -n hw.physmem');
                $pagesize = $this->intShell('pagesize');
                $pages = $this->intShell('( sysctl vm.stats.vm.v_cache_count | grep -Eo \'[0-9]+\' ; sysctl vm.stats.vm.v_inactive_count | grep -Eo \'[0-9]+\' ; sysctl vm.stats.vm.v_active_count | grep -Eo \'[0-9]+\' ) | awk \'{s+=$1} END {print s}\'');
                $used = $pages * $pagesize;
                break;
            case 'Windows':
                $total = $this->windowsMemory('(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory', 'ComputerSystem get TotalPhysicalMemory', false);
                $free = $this->windowsMemory('(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory', 'OS get FreePhysicalMemory', true);
                $used = $total - $free;
                break;
        }

        if ($total <= 0) {
            return null;
        }

        $used = max(0, min($used, $total));

        return ['total' => $total, 'used' => $used, 'free' => $total - $used];
    }

    /**
     * Windows memory value in bytes, PowerShell CIM with a wmic fallback for older systems.
     * FreePhysicalMemory is reported in kB, TotalPhysicalMemory in bytes.
     */
    private function windowsMemory(string $powershell, string $wmic, bool $in_kb): int {
        $output = $this->shell('powershell -NoProfile -Command "'.$powershell.'"');

        if (!is_numeric($output)) {
            $output = $this->shell('wmic '.$wmic.' | more +1');
        }

        $value = (int) $output;

        return $in_kb ? $value * 1024 : $value;
    }

    private function shell(string $command): ?string {
        static $available = null;

        if ($available === null) {
            $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));
            $available = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
        }

        if (!$available) {
            return null;
        }

        // Prepend the standard system paths so tools like sysctl (/usr/sbin) resolve even under a
        // restricted PATH (some PHP-FPM setups). The Windows shell handles its own commands.
        if (PHP_OS_FAMILY !== 'Windows') {
            $command = 'export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"; '.$command;
        }

        $output = @shell_exec($command);

        return is_string($output) && trim($output) !== '' ? trim($output) : null;
    }

    private function intShell(string $command): int {
        return (int) $this->shell($command);
    }

    /**
     * Extract a page count from a `vm_stat` line.
     */
    private function vmStatPages(string $vm_stat, string $label): int {
        return preg_match('/'.preg_quote($label, '/').':\s+(\d+)/', $vm_stat, $matches) === 1 ? (int) $matches[1] : 0;
    }

    /**
     * Extract a /proc/meminfo value (reported in kB) as bytes.
     */
    private function meminfoBytes(string $meminfo, string $key): int {
        return preg_match('/^'.preg_quote($key, '/').':\s+(\d+)/m', $meminfo, $matches) === 1 ? (int) $matches[1] * 1024 : 0;
    }
}
