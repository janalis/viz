<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\GraphViz\DotImage;

/**
 * A node's image, as dot documents `imagescale` and `imagepos`: its own size, or scaled to the
 * node keeping its shape (`true`), or stretched across (`width`), down (`height`) or both; then
 * set in the node at the corner, side or middle `imagepos` names, the middle by default.
 */
final class DotPictures
{
    /**
     * @param array{float, float, float, float} $box the node's: left, top, right, bottom
     * @return list<Mark>
     */
    public static function marks(DotImage $image, array $box, string $scale, string $position): array
    {
        [$left, $top, $right, $bottom] = $box;
        [$roomX, $roomY] = [$right - $left, $bottom - $top];
        [$width, $height] = match (\strtolower(\trim($scale))) {
            'true', 'yes', '1' => [$image->width * \min($roomX / $image->width, $roomY / $image->height), $image->height * \min($roomX / $image->width, $roomY / $image->height)],
            'width' => [$roomX, $image->height],
            'height' => [$image->width, $roomY],
            'both' => [$roomX, $roomY],
            default => [$image->width, $image->height],
        };
        $place = \strtolower(\trim($position));
        $x = match ($place[1] ?? 'c') {
            'l' => $left,
            'r' => $right - $width,
            default => $left + (($roomX - $width) / 2),
        };
        $y = match ($place[0] ?? 'm') {
            't' => $top,
            'b' => $bottom - $height,
            default => $top + (($roomY - $height) / 2),
        };

        return [new Mark(MarkKind::Image, [[$x, $y], [$x + $width, $y + $height]], $image->uri())];
    }
}
