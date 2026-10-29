<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Style;

/**
 * Colours in HSLuv, a lightness people see evenly, as PlantUML converts them: its
 * `HUSLColorConverter`, from hsluv-java (MIT, Alexei Boronine), each channel 0 to 1.
 */
final class PlantUmlHsluv
{
    private const array TO_RGB = [
        [3.240_969_941_904_521, -1.537_383_177_570_093, -0.498_610_760_293],
        [-0.969_243_636_280_87, 1.875_967_501_507_72, 0.041_555_057_407_175],
        [0.055_630_079_696_993, -0.203_976_958_888_97, 1.056_971_514_242_878],
    ];

    private const array TO_XYZ = [
        [0.412_390_799_265_95,  0.357_584_339_383_87, 0.180_480_788_401_83],
        [0.212_639_005_871_51,  0.715_168_678_767_75, 0.072_192_315_360_733],
        [0.019_330_818_715_591, 0.119_194_779_794_62, 0.950_532_152_249_66],
    ];

    private const float REF_U = 0.197_830_006_642_83;
    private const float REF_V = 0.468_319_994_938_79;
    private const float KAPPA = 903.296_296_2;
    private const float EPSILON = 0.008_856_451_6;

    /**
     * @param array{float, float, float} $rgb
     * @return array{float, float, float} its hue, saturation and lightness
     */
    public static function fromRgb(array $rgb): array
    {
        $linear = \array_map(static fn (float $c): float => $c > 0.040_45 ? (($c + 0.055) / 1.055) ** 2.4 : $c / 12.92, $rgb);
        [$x, $y, $z] = \array_map(static fn (array $row): float => ($row[0] * $linear[0]) + ($row[1] * $linear[1]) + ($row[2] * $linear[2]), self::TO_XYZ);
        $l = $y <= self::EPSILON ? $y * self::KAPPA : (116 * ($y ** (1 / 3))) - 16;
        if (0.0 === $l) {
            return [0.0, 0.0, 0.0];
        }
        $divider = $x + (15 * $y) + (3 * $z);
        [$u, $v] = [13 * $l * (((4 * $x) / $divider) - self::REF_U), 13 * $l * (((9 * $y) / $divider) - self::REF_V)];
        $c = \sqrt(($u * $u) + ($v * $v));
        $h = $c < 0.000_000_01 ? 0.0 : (\atan2($v, $u) * 180.0) / 3.141_592_653_589_793_2;
        $h = $h < 0 ? 360 + $h : $h;

        return match (true) {
            $l > 99.999_999_9 => [$h, 0.0, 100.0],
            $l < 0.000_000_01 => [$h, 0.0, 0.0],
            default => [$h, ($c / self::maxChroma($l, $h)) * 100, $l],
        };
    }

    /**
     * @param array{float, float, float} $hsl its hue, saturation and lightness
     * @return array{float, float, float}
     */
    public static function toRgb(array $hsl): array
    {
        [$h, $s, $l] = $hsl;
        [$l, $c] = match (true) {
            $l > 99.999_999_9 => [100.0, 0.0],
            $l < 0.000_000_01 => [0.0, 0.0],
            default => [$l, (self::maxChroma($l, $h) / 100) * $s],
        };
        if (0.0 === $l) {
            return [0.0, 0.0, 0.0];
        }
        $radians = ($h / 360.0) * 2 * \M_PI;
        $u = ((\cos($radians) * $c) / (13 * $l)) + self::REF_U;
        $v = ((\sin($radians) * $c) / (13 * $l)) + self::REF_V;
        $y = $l <= 8 ? $l / self::KAPPA : (($l + 16) / 116) ** 3;
        $x = -((9 * $y * $u) / ((($u - 4) * $v) - ($u * $v)));
        $z = ((9 * $y) - (15 * $v * $y) - ($v * $x)) / (3 * $v);

        return \array_map(static function (array $row) use ($x, $y, $z): float {
            $c = ($row[0] * $x) + ($row[1] * $y) + ($row[2] * $z);

            return $c <= 0.003_130_8 ? 12.92 * $c : (1.055 * ($c ** (1 / 2.4))) - 0.055;
        }, self::TO_RGB);
    }

    /** The most chroma a lightness holds at a hue, within the RGB gamut. */
    private static function maxChroma(float $l, float $h): float
    {
        $radians = ($h / 360) * \M_PI * 2;
        $sub = (($l + 16) ** 3) / 1_560_896;
        $sub = $sub > self::EPSILON ? $sub : $l / self::KAPPA;
        $least = \PHP_FLOAT_MAX;
        foreach (self::TO_RGB as [$m1, $m2, $m3]) {
            foreach ([0, 1] as $t) {
                $bottom = (((632_260 * $m3) - (126_452 * $m2)) * $sub) + (126_452 * $t);
                $slope = (((284_517 * $m1) - (94_839 * $m3)) * $sub) / $bottom;
                $intercept = ((((838_422 * $m3) + (769_860 * $m2) + (731_718 * $m1)) * $l * $sub) - (769_860 * $t * $l)) / $bottom;
                $length = $intercept / (\sin($radians) - ($slope * \cos($radians)));
                $least = $length >= 0 ? \min($least, $length) : $least;
            }
        }

        return $least;
    }
}
