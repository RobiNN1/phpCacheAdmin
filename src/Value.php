<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace RobiNN\Pca;

use JsonException;

class Value {
    /**
     * Format and decode value.
     *
     * @return array<int, mixed>
     */
    public static function format(string $value): array {
        // It's only used to display the name in the UI
        $encoder = null;
        $is_formatted = false;

        if (!json_validate($value)) {
            // Decoding must be done first, in case there is data that can also be formatted
            $value = self::decoded($value, $encoder);
            $value = self::formatted($value, $is_formatted);
        }

        // Always pretty print the JSON because some formatters may return the value as JSON
        $value = self::prettyPrintJson($value);

        return [$value, $encoder, $is_formatted];
    }

    public static function decoded(string $value, ?string &$encoder = null): string {
        foreach (Config::get('converters', []) as $name => $decoder) {
            if (is_callable($decoder['view']) && ($decoded = $decoder['view']($value)) !== null) {
                $encoder = (string) $name;

                return $decoded;
            }
        }

        return $value;
    }

    public static function formatted(string $value, bool &$is_formatted = false): string {
        foreach (Config::get('formatters', []) as $formatter) {
            if (is_callable($formatter) && $formatter($value) !== null) {
                $is_formatted = true;

                return $formatter($value);
            }
        }

        return $value;
    }

    public static function prettyPrintJson(string $value): string {
        if (!is_numeric($value) && json_validate($value)) {
            try {
                $json_array = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
                $value = json_encode($json_array, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

                return '<pre class="json-code">'.htmlspecialchars($value).'</pre>';
            } catch (JsonException) {
                return htmlspecialchars($value);
            }
        }

        return htmlspecialchars($value);
    }

    /**
     * Decode/Encode value.
     *
     * Used in forms.
     */
    public static function converter(string $value, string $converter, string $type): string {
        if ($converter === 'none') {
            return $value;
        }

        $converters = (array) Config::get('converters', []);

        // $type can be view (decode) or save (encode)
        if (
            is_callable($converters[$converter][$type]) &&
            ($converted = $converters[$converter][$type]($value)) !== null
        ) {
            return $converted;
        }

        return $value;
    }
}
