<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;

/**
 * A mind map drawn as PlantUML's `MindMap` draws it: the ideas on the right — or below — as
 * one finger from the root, those on the left — or above — as another, which leaves the root
 * to the first when both have ideas; each mind map as thick as its thicker half, under the one
 * before, 10 more room past the widest, set in the document's frame.
 */
final class MindMapDrawing
{
    public static function draw(PlantUmlMindMap $diagram): Drawing
    {
        $vertical = Direction::TopBottom === $diagram->direction;
        $styles = $diagram->document->styles;
        $marks = [];
        [$width, $height] = [0.0, 0.0];
        foreach ($diagram->maps as $map) {
            [$regular, $reverse] = [null, null];
            if ([] !== $map->left->children) {
                $reverse = new Finger($map->left, $styles, -1, $vertical);
            }
            if (null === $reverse || [] !== $map->right->children) {
                $regular = new Finger($map->right, $styles, 1, $vertical);
            }
            if (null !== $reverse && null !== $regular) {
                $reverse->drawPhalanx = false;
            }
            $half = \max(self::half($regular), self::half($reverse));
            $before = self::x12($reverse);
            $length = $before + self::x12($regular);
            $origin = $vertical ? [$half, $height + $before] : [$before, $height + $half];
            foreach ([$regular, $reverse] as $finger) {
                \array_push($marks, ...$finger?->marks($origin) ?? []);
            }
            $width = \max($width, $vertical ? 2 * $half : $length);
            $height += $vertical ? $length : 2 * $half;
        }

        // As PlantUML frames it for `mainframe`: 1 wider than what is drawn reaches across, as tall as its branches stand.
        [$minX, , $maxX] = [] === $marks ? [0.0, 0.0, 0.0] : PlantUmlFrame::limits($marks);

        return PlantUmlFrame::drawing($diagram->document, $marks, $width + 10, $height, content: [$maxX - $minX + 1, $height, 0.0, 0.0]);
    }

    private static function half(?Finger $finger): float
    {
        return null === $finger ? 0.0 : $finger->thickness() / 2;
    }

    /** How long a finger reaches, with the room after its root. */
    private static function x12(?Finger $finger): float
    {
        return null === $finger ? 0.0 : $finger->elongation() + $finger->x12();
    }
}
