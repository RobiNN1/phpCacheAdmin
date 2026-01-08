<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

class Format {
    public static function bytes(int $bytes, int $decimals = 2): string {
        if ($bytes > 1_048_576) {
            return sprintf('%sMB', self::number($bytes / 1_048_576, $decimals));
        }

        if ($bytes > 1024) {
            return sprintf('%sKB', self::number($bytes / 1024, $decimals));
        }

        return sprintf('%sB', self::number($bytes, $decimals));
    }

    /**
     * @link https://www.php.net/manual/en/faq.using.php#faq.using.shorthandbytes
     */
    public static function iniSizeToBytes(string $ini_size): int {
        $size = (int) substr($ini_size, 0, -1);
        $unit = strtoupper(substr($ini_size, -1));

        switch ($unit) {
            case 'K':
                $size *= 1024;
                break;
            case 'M':
                $size *= 1024 * 1024;
                break;
            case 'G':
                $size *= 1024 * 1024 * 1024;
                break;
            default:
                break;
        }

        return $size;
    }

    public static function seconds(int $time, int $granularity = PHP_INT_MAX): string {
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

        $remaining_seconds = $minute_seconds % $seconds_in_minute;
        $seconds = ceil($remaining_seconds);

        $sections = [
            'day'    => (int) $days,
            'hour'   => (int) $hours,
            'minute' => (int) $minutes,
            'second' => (int) $seconds,
        ];

        $time_parts = [];

        foreach ($sections as $name => $value) {
            if ($value > 0 && count($time_parts) < $granularity) {
                $time_parts[] = $value.' '.$name.($value === 1 ? '' : 's');
            }
        }

        return $time_parts !== [] ? implode(' ', $time_parts) : '0 seconds';
    }

    public static function time(int $time): string {
        if ($time === 0) {
            return 'Never';
        }

        $format = Config::get('timeformat', 'd. m. Y H:i:s');

        try {
            return (new DateTimeImmutable('@'.$time))
                ->setTimezone(new DateTimeZone(Config::get('timezone', date_default_timezone_get())))
                ->format($format);
        } catch (Exception) {
            return date($format, $time);
        }
    }

    public static function timeDiff(int $from, ?int $to = null): string {
        $units = [
            'year'   => 365 * 24 * 60 * 60,
            'month'  => 30 * 24 * 60 * 60,
            'week'   => 7 * 24 * 60 * 60,
            'day'    => 24 * 60 * 60,
            'hour'   => 60 * 60,
            'minute' => 60,
            'second' => 1,
        ];

        $diff = ($to ?? time()) - $from;

        foreach ($units as $name => $seconds) {
            if ($diff >= $seconds) {
                $value = round($diff / $seconds);

                return sprintf('%d %s%s ago', $value, $name, $value > 1 ? 's' : '');
            }
        }

        return '1 second ago';
    }

    /**
     * Formats a TTL (remaining time) in readable format with countdown.
     *
     * @param int $expiry_timestamp Expiration timestamp (0 = never expires, -1 = does not expire)
     */
    public static function countdown(int $expiry_timestamp): string {
        if ($expiry_timestamp <= 0) {
            return "Doesn't expire";
        }

        $remaining = $expiry_timestamp - time();

        if ($remaining <= 0) {
            return 'Expired';
        }

        return self::seconds($remaining, 1);
    }

    public static function number(float $number, int $decimals = 0): string {
        // If the number is effectively an integer (e.g., 50.00), force decimals to 0
        if ($number == (int)$number) {
            $decimals = 0;
        }

        return number_format(
            $number,
            $decimals,
            Config::get('decimalsep', ','),
            Config::get('thousandssep', ' ')
        );
    }
}
