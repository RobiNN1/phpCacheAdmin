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
    public static function bytes(int $bytes): string {
        if ($bytes > 1_048_576) {
            return sprintf('%sMB', self::number($bytes / 1_048_576, 2));
        }

        if ($bytes > 1024) {
            return sprintf('%sKB', self::number($bytes / 1024, 2));
        }

        return sprintf('%sB', self::number($bytes, 2));
    }

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

    public static function time(int $time): string {
        if ($time === 0) {
            return 'Never';
        }

        $format = Config::get('time-format', 'd. m. Y H:i:s');

        try {
            return (new DateTimeImmutable('@'.$time))
                ->setTimezone(new DateTimeZone(Config::get('timezone', date_default_timezone_get())))
                ->format($format);
        } catch (Exception $e) {
            return date($format, $time);
        }
    }

    public static function number(float $number, int $decimals = 0): string {
        return number_format(
            $number,
            $decimals,
            Config::get('decimal-sep', ','),
            Config::get('thousands-sep', ' ')
        );
    }
}
