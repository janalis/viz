<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Svek\Svek;

/**
 * A Chen EER diagram laid out as svek lays it out and drawn as PlantUML draws it: each
 * element a node of its shape's size, its links stopping at its outline, an attribute's a
 * rank long, the others two, a cardinality in a link's middle; the subset sign across a
 * subclass link's middle, as PlantUML's `MiddleSubset` draws it. In points, set in the
 * document's frame.
 */
final class ChenDrawing
{
    /** The subset sign's radius, and how far its tails run on. */
    private const float SIGN_RADIUS = 6.0;
    private const float SIGN_TAIL = 10.0;
    /** How PlantUML's `MiddleSubset` turns a superset's sign, and a subset's, in degrees. */
    private const float SUPERSET = -45.0;
    private const float SUBSET = 135.0;

    /** @var array<string, ChenBox> */
    private array $boxes = [];
    /** @var array<int, int> */
    private array $edges = [];

    private function __construct(
        private readonly PlantUmlChen $diagram,
    ) {}

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlChen $diagram): \Generator
    {
        $drawing = new self($diagram);
        $placing = $drawing->graph()->place($diagram->direction);
        yield from $placing;

        return PlantUmlFrame::svek($diagram->document, $drawing->paint($placing->getReturn()));
    }

    private function graph(): Svek
    {
        $svek = new Svek();
        foreach ($this->diagram->elements as $element) {
            $box = ChenBox::of($element, $this->diagram->document->styles);
            $this->boxes[$element->id] = $box;
            $svek->node($element->id, $box->width, $box->height, shape: $box->shape());
        }
        foreach ($this->diagram->links as $index => $link) {
            [$width, $height] = $this->label($index)->size();
            $margin = Svek::labelMargin($link->from, $link->to);
            $this->edges[$index] = $svek->edge($link->from, $link->to, $link->length, $width > 0 ? $width + $margin : 0.0, $height > 0 ? $height + $margin : 0.0);
        }

        return $svek;
    }

    private function label(int $index): LinkLabel
    {
        $link = $this->diagram->links[$index];
        $styles = $this->diagram->document->styles;

        return LinkLabel::of(null === $link->label ? [] : ClassReader::split($link->label), $styles->style(['root', 'element', 'activityDiagram', 'arrow']), [], $styles->style([
            'root',
            'element',
            'chenEerDiagram',
            'note',
        ]));
    }

    /** @return list<Mark> */
    private function paint(Placement $placement): array
    {
        $styles = $this->diagram->document->styles;
        $style = $styles->style(['root', 'element', 'activityDiagram', 'arrow']);
        $background = $styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        $shapes = \array_map(static fn (ChenBox $box): string => $box->shape(), $this->boxes);
        $marks = [];
        foreach ($this->edges as $index => $edge) {
            $link = $this->diagram->links[$index];
            \array_push($marks, ...LinkPainter::marks($link, $placement, $edge, $this->label($index), $style, $background, $shapes));
            $superset = $this->diagram->signs[$index] ?? null;
            if (null === $superset) {
                continue;
            }
            $from = $placement->endpoints[$link->from];
            $to = $placement->endpoints[$link->to];
            $points = BoxClip::clipped(Route::smooth($placement->routes[$edge] ?? [[$from->x, $from->y], [$to->x, $to->y]]), $from, $to, $shapes);
            \array_push($marks, ...self::sign($points, $superset ? self::SUPERSET : self::SUBSET, ClassColors::back($link->color) ?? $style->color('LineColor', '#181818')));
        }
        foreach ($this->boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            \array_push($marks, ...$box->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }

        return $marks;
    }

    /**
     * The subset sign at a line's middle: half a circle across it, open along it — towards its
     * head for a superset — its two ends running on 10.
     *
     * @param list<array{float, float}> $points
     * @param float $rotation the sign's own turn, in degrees: a superset's or a subset's
     * @return list<Mark>
     */
    private static function sign(array $points, float $rotation, string $color): array
    {
        [[$x, $y], $angle] = self::middle($points);
        // As svek turns the sign: by the line's angle, counter-clockwise, less 45.
        $turn = \deg2rad(-\rad2deg($angle) - 45 + $rotation);
        [$sin, $cos] = [\sin($turn), \cos($turn)];
        $arc = [];
        for ($step = 0; $step <= 16; ++$step) {
            $theta = $turn + (\M_PI / 2) + ((\M_PI * $step) / 16);
            $arc[] = [$x + (\sin($theta) * self::SIGN_RADIUS), $y + (\cos($theta) * self::SIGN_RADIUS)];
        }
        $tail = [self::SIGN_TAIL * $sin, self::SIGN_TAIL * $cos];
        $ends = [[$x + (self::SIGN_RADIUS * $cos), $y - (self::SIGN_RADIUS * $sin)], [$x - (self::SIGN_RADIUS * $cos), $y + (self::SIGN_RADIUS * $sin)]];

        return [
            new Mark(MarkKind::Polyline, $arc, stroke: $color, strokeWidth: 1.5),
            ...\array_map(static fn (array $end): Mark => new Mark(MarkKind::Polyline, [$end, [$end[0] + $tail[0], $end[1] + $tail[1]]], stroke: $color, strokeWidth: 1.5), $ends),
        ];
    }

    /**
     * @param list<array{float, float}> $points two at least, as a clipped path keeps
     * @return array{array{float, float}, float} the point half way along a line, and the line's angle there
     */
    private static function middle(array $points): array
    {
        $lengths = [];
        for ($index = 1, $count = \count($points); $index < $count; ++$index) {
            $lengths[] = \hypot($points[$index][0] - $points[$index - 1][0], $points[$index][1] - $points[$index - 1][1]);
        }
        $half = \array_sum($lengths) / 2;
        $index = 0;
        while ($half > $lengths[$index] && $index < (\count($lengths) - 1)) {
            $half -= $lengths[$index++];
        }
        [$a, $b, $length] = [$points[$index], $points[$index + 1], $lengths[$index]];
        $part = $length > 0 ? \min(1.0, $half / $length) : 0.0;

        return [[$a[0] + (($b[0] - $a[0]) * $part), $a[1] + (($b[1] - $a[1]) * $part)], \atan2($b[1] - $a[1], $b[0] - $a[0])];
    }
}
