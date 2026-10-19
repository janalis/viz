<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\PlantUML\Style\PlantUmlColor;

/** A colour spec written after an element: `#pink`, or `#back:pink;line:red;text:blue` and the like. */
final class ClassColors
{
    /** The background it sets, as hex; null when it sets none. */
    public static function back(?string $spec): ?string
    {
        return self::part($spec, 'back');
    }

    public static function line(?string $spec): ?string
    {
        return self::part($spec, 'line');
    }

    public static function text(?string $spec): ?string
    {
        return self::part($spec, 'text');
    }

    private static function part(?string $spec, string $name): ?string
    {
        if (null === $spec) {
            return null;
        }
        $spec = \ltrim(\trim($spec), characters: '#');
        foreach (\explode(';', $spec) as $part) {
            [$key, $value] = \str_contains($part, ':') ? \explode(':', $part, limit: 2) : ['back', $part];
            if (\strtolower(\trim($key)) === $name) {
                return PlantUmlColor::parse($value);
            }
        }

        return null;
    }
}
