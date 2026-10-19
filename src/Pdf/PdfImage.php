<?php

declare(strict_types=1);

namespace Janalis\Viz\Pdf;

/** An image XObject's samples: a JPEG passed through as is, or 8-bit RGB with an optional alpha mask. */
final readonly class PdfImage
{
    public function __construct(
        public int $width,
        public int $height,
        public string $colorSpace,
        public string $data,
        public bool $jpeg = false,
        /** @var ?string one 8-bit opacity sample per pixel */
        public ?string $alpha = null,
    ) {}

    /** A grayscale or RGB JPEG, which PDF decodes itself; null for anything else, CMYK included. */
    public static function jpeg(string $bytes): ?self
    {
        \set_error_handler(static fn (): bool => true);
        try {
            $size = \getimagesizefromstring($bytes);
        } finally {
            \restore_error_handler();
        }
        if (false === $size || \IMAGETYPE_JPEG !== $size[2]) {
            return null;
        }

        return match ($size['channels']) {
            1 => new self($size[0], $size[1], 'DeviceGray', $bytes, jpeg: true),
            3 => new self($size[0], $size[1], 'DeviceRGB', $bytes, jpeg: true),
            default => null,
        };
    }

    /** A decoded GD picture as RGB samples, with a soft mask only where some pixel is translucent. */
    public static function gd(\GdImage $picture): self
    {
        if (!\imageistruecolor($picture)) {
            \imagepalettetotruecolor($picture);
        }
        [$width, $height] = [\imagesx($picture), \imagesy($picture)];
        $rgb = '';
        $alpha = '';
        $translucent = false;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $pixel = (int) \imagecolorat($picture, $x, $y);
                $rgb .= \chr(($pixel >> 16) & 0xFF).\chr(($pixel >> 8) & 0xFF).\chr($pixel & 0xFF);
                // GD's alpha runs from 0 (opaque) to 127 (transparent).
                $transparency = ($pixel >> 24) & 0x7F;
                $translucent = $translucent || 0 !== $transparency;
                $alpha .= \chr(255 - (int) \round(($transparency * 255) / 127));
            }
        }

        return new self($width, $height, 'DeviceRGB', $rgb, alpha: $translucent ? $alpha : null);
    }
}
