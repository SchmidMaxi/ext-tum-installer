<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

class TypeService
{
    public const TYPE_UNDEFINED = 0;
    public const TYPE_STRING = 1;
    public const TYPE_NUMERIC = 2;
    public const TYPE_BINARY_ARRAY = 4;
    public const TYPE_CONFIG = 8;
    public const TYPE_DATABASE = 16;
    public const TYPE_DATETIME = 32;
    public const TYPE_MIXED_STRING = 64;
    public const TYPE_MATH_OPERATION = 128;
    public const TYPE_SORTING = 256;

    public function getType(mixed $data): int
    {
        if (is_array($data)) {
            return self::TYPE_BINARY_ARRAY;
        }
        if (is_numeric($data)) {
            return self::TYPE_NUMERIC;
        }
        if (is_string($data)) {
            if (str_starts_with($data, 'Datetime::')) {
                return self::TYPE_DATETIME;
            }
            if (str_starts_with($data, 'Sorting::')) {
                return self::TYPE_SORTING;
            }
            // Verschachtelte Platzhalter { ... }
            preg_match_all('/{([^{]*?)}/', $data, $matchesGroup);
            $subtypes = $matchesGroup[0] ?? [];

            // Wenn der ganze String ein Platzhalter ist (falsch positiv vermeiden)
            if (!empty($subtypes) && $subtypes[0] === $data) {
                $subtypes = [];
            }

            if (empty($subtypes) && str_starts_with($data, '{$') && str_ends_with($data, '}')) {
                return self::TYPE_CONFIG;
            }
            if (empty($subtypes) && str_starts_with($data, '{db::') && str_ends_with($data, '}')) {
                return self::TYPE_DATABASE;
            }
            if ((str_contains($data, '{$') || str_contains($data, '{db::')) && str_contains($data, '}')) {
                return self::TYPE_MIXED_STRING;
            }
            if (str_starts_with($data, 'MATH::')) {
                return self::TYPE_MATH_OPERATION;
            }
            return self::TYPE_STRING;
        }
        return self::TYPE_UNDEFINED;
    }
}