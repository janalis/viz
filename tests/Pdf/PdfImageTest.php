<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Pdf;

use Janalis\Viz\Pdf\PdfImage;
use PHPUnit\Framework\TestCase;

/** Image samples: JPEGs pass through, GD pictures become RGB with an alpha mask when needed. */
final class PdfImageTest extends TestCase
{
    public function testGrayAndRgbJpegsPassThrough(): void
    {
        $jpeg = self::jpeg();
        $image = PdfImage::jpeg($jpeg);
        $this->assertNotNull($image);
        $this->assertSame([5, 3, 'DeviceRGB', $jpeg, true, null], [$image->width, $image->height, $image->colorSpace, $image->data, $image->jpeg, $image->alpha]);
        $gray = PdfImage::jpeg(self::components($jpeg, 1));
        $this->assertNotNull($gray);
        $this->assertSame([5, 3, 'DeviceGray', true], [$gray->width, $gray->height, $gray->colorSpace, $gray->jpeg]);
        $this->assertNull(PdfImage::jpeg(self::components($jpeg, 4)), 'CMYK is left to GD');
        $this->assertNull(PdfImage::jpeg(self::png(\imagecreatetruecolor(1, height: 1))));
        \error_clear_last();
        $this->assertNull(PdfImage::jpeg('abc'), 'too short to read');
        $this->assertNull(\error_get_last(), 'the decoder notice is swallowed');
    }

    public function testOpaquePicturesNeedNoMask(): void
    {
        $picture = \imagecreatetruecolor(2, height: 1);
        \imagesetpixel($picture, x: 0, y: 0, color: 0x10_20_30);
        \imagesetpixel($picture, x: 1, y: 0, color: 0xA0_B0_C0);
        $image = PdfImage::gd($picture);
        $this->assertSame([2, 1, 'DeviceRGB', "\x10\x20\x30\xA0\xB0\xC0", false, null], [$image->width, $image->height, $image->colorSpace, $image->data, $image->jpeg, $image->alpha]);
    }

    public function testTranslucentPicturesCarryAnAlphaSamplePerPixel(): void
    {
        $picture = \imagecreatetruecolor(2, height: 2);
        \imagealphablending($picture, enable: false);
        \imagesetpixel($picture, x: 0, y: 0, color: 0x00_FF_00_00);
        \imagesetpixel($picture, x: 1, y: 0, color: 0x7F_00_FF_00);
        \imagesetpixel($picture, x: 0, y: 1, color: 0x40_00_00_FF);
        \imagesetpixel($picture, x: 1, y: 1, color: 0x01_FF_FF_FF);
        $image = PdfImage::gd($picture);
        $this->assertSame("\xFF\x00\x00\x00\xFF\x00\x00\x00\xFF\xFF\xFF\xFF", $image->data);
        $this->assertSame("\xFF\x00\x7E\xFD", $image->alpha);
    }

    public function testPalettePicturesAreReadAsTrueColor(): void
    {
        $picture = \imagecreate(1, height: 1);
        \imagecolorallocate($picture, red: 0x12, green: 0x34, blue: 0x56);
        $image = PdfImage::gd($picture);
        $this->assertSame("\x12\x34\x56", $image->data);
        $this->assertNull($image->alpha);
    }

    public static function jpeg(int $width = 5, int $height = 3): string
    {
        $picture = \imagecreatetruecolor($width, $height);
        \imagefill($picture, x: 0, y: 0, color: 0x33_66_99);
        \ob_start();
        \imagejpeg($picture);

        return (string) \ob_get_clean();
    }

    public static function png(\GdImage $picture): string
    {
        \ob_start();
        \imagepng($picture);

        return (string) \ob_get_clean();
    }

    /** The JPEG with its frame header declaring another component count. */
    private static function components(string $jpeg, int $count): string
    {
        $frame = (int) \strpos($jpeg, needle: "\xFF\xC0");

        return \substr_replace($jpeg, \chr($count), $frame + 9, length: 1);
    }
}
