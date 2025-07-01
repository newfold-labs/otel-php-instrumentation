<?php

namespace NewfoldLabs\CustomPhp\Instrumentation;

use OpenTelemetry\API\Metrics\MeterInterface;
use NewfoldLabs\CustomPhp\Instrumentation\Context\ConfigContext;

/**
 * Class SystemMetrics
 *
 * Collects system and process metrics and registers them with the provided MeterInterface.
 * Metrics include CPU load, memory usage, disk usage, network I/O, process threads,
 * and PHP-FPM specific metrics.
 *
 * @package NewfoldLabs\CustomPhp\Instrumentation\SystemMetrics
 * @author Suraj Rathod
 */
class SystemMetrics
{
    /**
     * Register system metrics observable gauges with the meter.
     *
     * @param MeterInterface $meter
     * @param string $jobName
     * @return void
     */
    public static function register(MeterInterface $meter, string $jobName): void
    {
        if (!self::isHealthCheckRequest()) {
            return;
        }

        $host = gethostname();
        $attributes = [
            'job' => $jobName,
            'host' => $host,
            'service' => $jobName,
        ];

        // CPU Load averages
        $meter->createObservableGauge('system.cpu.load.1min')
            ->observe(fn($o) => $o->observe(self::getLoadAvg(0), $attributes));
        $meter->createObservableGauge('system.cpu.load.5min')
            ->observe(fn($o) => $o->observe(self::getLoadAvg(1), $attributes));
        $meter->createObservableGauge('system.cpu.load.15min')
            ->observe(fn($o) => $o->observe(self::getLoadAvg(2), $attributes));

        // Memory usage in MB
        $meter->createObservableGauge('system.memory.usage.mb')
            ->observe(fn($o) => $o->observe(self::getMemoryUsageMB(), $attributes));
        $meter->createObservableGauge('system.memory.peak.mb')
            ->observe(fn($o) => $o->observe(self::getMemoryPeakMB(), $attributes));
        $meter->createObservableGauge('system.memory.total.mb')
            ->observe(fn($o) => $o->observe(self::getMemInfo('MemTotal'), $attributes));
        $meter->createObservableGauge('system.memory.available.mb')
            ->observe(fn($o) => $o->observe(self::getMemInfo('MemAvailable'), $attributes));

        // Disk usage percent
        $meter->createObservableGauge('system.disk.usage.percent')
            ->observe(fn($o) => $o->observe(self::getDiskUsagePercent(), $attributes));

        // Network bytes in/out on eth0
        $meter->createObservableGauge('system.network.in.bytes')
            ->observe(fn($o) => $o->observe(self::getNetworkBytes('eth0', 1), $attributes));
        $meter->createObservableGauge('system.network.out.bytes')
            ->observe(fn($o) => $o->observe(self::getNetworkBytes('eth0', 9), $attributes));

        // Process metrics
        $meter->createObservableGauge('process.cpu.usage.percent')
            ->observe(fn($o) => $o->observe(0.0, $attributes)); // Placeholder: Implement CPU percent if needed
        $meter->createObservableGauge('process.memory.usage.mb')
            ->observe(fn($o) => $o->observe(self::getMemoryUsageMB(), $attributes));
        $meter->createObservableGauge('process.thread.count')
            ->observe(fn($o) => $o->observe(self::getProcessThreadCount(), $attributes));

        // PHP-FPM metrics
        $fpmMetrics = self::getFpmMetrics();
        $meter->createObservableGauge('phpfpm_process_count')
            ->observe(fn($o) => $o->observe($fpmMetrics['phpfpm_process_count'] ?? 0, $attributes));
        $meter->createObservableGauge('phpfpm_total_cpu_percent')
            ->observe(fn($o) => $o->observe($fpmMetrics['phpfpm_total_cpu_percent'] ?? 0, $attributes));
        $meter->createObservableGauge('phpfpm_total_mem_mb')
            ->observe(fn($o) => $o->observe($fpmMetrics['phpfpm_total_mem_mb'] ?? 0, $attributes));
        $meter->createObservableGauge('phpfpm_scrape_failures')
            ->observe(fn($o) => $o->observe($fpmMetrics['phpfpm_scrape_failures'] ?? 0, $attributes));
    }

    /**
     * Determines if the current request is a health check request
     * based on configured URIs.
     *
     * @return bool True if current request URI matches configured health check URIs.
     */
    public static function isHealthCheckRequest(): bool
    {
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';

        $metricsConfig = ConfigContext::get()->get('metrics', false);

        if ($metricsConfig && isset($metricsConfig['healthcheck_uri'])) {
            foreach ($metricsConfig['healthcheck_uri'] as $uri) {
                if (strpos($currentUri, $uri) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Gets system load average at specified index (0=1min, 1=5min, 2=15min).
     *
     * @param int $index
     * @return float
     */
    private static function getLoadAvg(int $index): float
    {
        $load = sys_getloadavg();
        return $load[$index] ?? 0;
    }

    /**
     * Gets current memory usage of the PHP process in MB.
     *
     * @return float
     */
    private static function getMemoryUsageMB(): float
    {
        return round(memory_get_usage(true) / 1048576, 2);
    }

    /**
     * Gets peak memory usage of the PHP process in MB.
     *
     * @return float
     */
    private static function getMemoryPeakMB(): float
    {
        return round(memory_get_peak_usage(true) / 1048576, 2);
    }

    /**
     * Reads /proc/meminfo and returns the value (in MB) for the specified key.
     *
     * @param string $key
     * @return float
     */
    private static function getMemInfo(string $key): float
    {
        $lines = @file('/proc/meminfo');
        if (!$lines) {
            return 0;
        }

        foreach ($lines as $line) {
            if (strpos($line, $key) === 0) {
                preg_match('/\d+/', $line, $matches);
                return isset($matches[0]) ? round((int) $matches[0] / 1024, 2) : 0.0;
            }
        }
        return 0.0;
    }

    /**
     * Calculates disk usage percentage for the root filesystem.
     *
     * @return float
     */
    private static function getDiskUsagePercent(): float
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        return $total > 0 ? round((($total - $free) / $total) * 100, 2) : 0;
    }

    /**
     * Reads /proc/net/dev and returns the number of bytes
     * for the specified network interface and column.
     *
     * @param string $interface
     * @param int $column
     * @return int
     */
    private static function getNetworkBytes(string $interface, int $column): int
    {
        $lines = @file('/proc/net/dev');
        if (!$lines) {
            return 0;
        }

        foreach ($lines as $line) {
            if (strpos($line, $interface . ':') !== false) {
                $parts = preg_split('/\s+/', trim(str_replace(':', ' ', $line)));
                return isset($parts[$column]) ? (int) $parts[$column] : 0;
            }
        }
        return 0;
    }

    /**
     * Gets the number of threads for the current PHP process.
     *
     * @return int
     */
    private static function getProcessThreadCount(): int
    {
        $pid = getmypid();
        $status = @file_get_contents("/proc/$pid/status");
        if (!$status) {
            return 0;
        }

        if (preg_match('/Threads:\s+(\d+)/', $status, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Collects PHP-FPM related metrics such as process count,
     * CPU and memory usage aggregated across all PHP-FPM processes.
     *
     * @return array{
     *     phpfpm_process_count: int,
     *     phpfpm_total_cpu_percent: float,
     *     phpfpm_total_mem_mb: float,
     *     phpfpm_scrape_failures: int
     * }
     */
    private static function getFpmMetrics(): array
    {
        $dir = '/proc';
        $totalCpu = 0.0;
        $totalMem = 0.0;
        $processCount = 0;

        // _SC_CLK_TCK is usually 100 on Linux systems
        $clockTicks = defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux' ? 100 : 100;

        foreach (scandir($dir) as $pid) {
            if (!ctype_digit($pid)) {
                continue;
            }

            $cmdlinePath = "$dir/$pid/cmdline";
            if (!is_readable($cmdlinePath)) {
                continue;
            }

            $cmdline = file_get_contents($cmdlinePath);
            if (strpos($cmdline, 'php-fpm') === false) {
                continue;
            }

            $statPath = "$dir/$pid/stat";
            if (!is_readable($statPath)) {
                continue;
            }

            $stat = file_get_contents($statPath);
            $fields = explode(' ', $stat);

            // utime = 14th field (index 13), stime = 15th field (index 14)
            $utime = isset($fields[13]) ? (int) $fields[13] : 0;
            $stime = isset($fields[14]) ? (int) $fields[14] : 0;

            // CPU time in seconds (approximate)
            $cpu = ($utime + $stime) / $clockTicks;
            $totalCpu += $cpu;

            $statusPath = "$dir/$pid/status";
            if (is_readable($statusPath)) {
                $status = file_get_contents($statusPath);
                if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                    $memKb = (int) $matches[1];
                    $totalMem += $memKb / 1024; // MB
                }
            }

            $processCount++;
        }

        return [
            'phpfpm_process_count' => $processCount,
            'phpfpm_total_cpu_percent' => round($totalCpu, 2),
            'phpfpm_total_mem_mb' => round($totalMem, 2),
            'phpfpm_scrape_failures' => 0,
        ];
    }
}
