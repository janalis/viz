<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/** The image formats every renderer is asked for. */
enum Format: string
{
    case Svg = 'svg';
    case Png = 'png';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Svg => 'image/svg+xml',
            self::Png => 'image/png',
            self::Pdf => 'application/pdf',
        };
    }

    /** The format a file name asks for, by its extension; null when it names none of them. */
    public static function forPath(string $path): ?self
    {
        return self::tryFrom(\strtolower(\pathinfo($path, \PATHINFO_EXTENSION)));
    }
}
