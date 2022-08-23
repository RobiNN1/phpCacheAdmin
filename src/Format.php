<?php
/**
 * This file is part of phpCacheAdmin.
 *
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

class Format {
    /**
     * Format bytes.
     *
     * @param int $bytes
     *
     * @return string
     */
    public static function bytes(int $bytes): string {
        if ($bytes > 1048576) {
            return sprintf('%.2fMB', $bytes / 1048576);
        }

        if ($bytes > 1024) {
            return sprintf('%.2fkB', $bytes / 1024);
        }

        return sprintf('%dbytes', $bytes);
    }

    /**
     * Format seconds.
     *
     * @param int $time
     *
     * @return string
     */
    public static function seconds(int $time): string {
        if ($time === -1) {
            return (string) $time;
        }

        $seconds_in_minute = 60;
        $seconds_in_hour = 60 * $seconds_in_minute;
        $seconds_in_day = 24 * $seconds_in_hour;

        $days = floor($time / $seconds_in_day);

        $hour_seconds = $time % $seconds_in_day;
        $hours = floor($hour_seconds / $seconds_in_hour);

        $minute_seconds = $hour_seconds % $seconds_in_hour;
        $minutes = floor($minute_seconds / $seconds_in_minute);

        $remainingSeconds = $minute_seconds % $seconds_in_minute;
        $seconds = ceil($remainingSeconds);

        $time_parts = [];
        $sections = [
            'day'    => (int) $days,
            'hour'   => (int) $hours,
            'minute' => (int) $minutes,
            'second' => (int) $seconds,
        ];

        foreach ($sections as $name => $value) {
            if ($value > 0) {
                $time_parts[] = $value.' '.$name.($value === 1 ? '' : 's');
            }
        }

        return implode(' ', $time_parts);
    }

    /**
     * Format timestamp.
     *
     * @param int $time
     *
     * @return string
     */
    public static function time(int $time): string {
        if ($time === 0) {
            return 'Never';
        }

        try {
            return (new DateTimeImmutable('@'.$time))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format(Config::get('timeformat'));
        } catch (Exception $e) {
            return date(Config::get('timeformat'), $time);
        }
    }

    /**
     * Format number.
     *
     * @param int $number
     *
     * @return string
     */
    public static function number(int $number): string {
        return number_format($number, 0, ',', ' ');
    }
}
