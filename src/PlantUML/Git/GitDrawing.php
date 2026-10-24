<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Git;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Layout\Engine\LayoutEdge;
use Janalis\Viz\Layout\Engine\LayoutGraph;
use Janalis\Viz\Layout\Engine\LayoutNode;
use Janalis\Viz\Layout\Engine\Spacing;
use Janalis\Viz\PlantUML\Creole\CreoleFont;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use Janalis\Viz\PlantUML\Draw\PlantUmlArrow;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\RoundedBox;

/**
 * A git log laid out as PlantUML's `SmetanaForGit` lays it out: each node a box of dot as big
 * as its `MagicBox`, rounded to whole points, ranks 0.35 inch apart, each commit above its
 * parents, all as far down as half the top box's height when mirrored as PlantUML mirrors them; each node's comment, on a top one only, over a rounded box of its commits' names,
 * and each child joined to its parents by a dashed curve, as `GitCurve` draws it.
 */
final class GitDrawing
{
    private const float RANK_SEPARATION = 25.2;
    private const float NODE_SEPARATION = 18.0;
    /** How tall dot makes a box at least, as its default label `\N` needs. */
    private const float LOWEST = 24.0;
    /** How long dot draws an arrowhead at `arrowsize=.7`. */
    private const float ARROW = 7.0;
    /** PlantUML's sans-serif line, against its size. */
    private const float SANS = 1.177_734_375;
    private const float COMMENT = 13.0;
    private const float NAME = 15.0;

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlGit $git): \Generator
    {
        $style = $git->document->styles->style(['root', 'element', 'gitDiagram']);
        $line = $style->color('LineColor', '#181818');
        $font = $style->color('FontColor', '#000000');
        // With no commit, as measured on PlantUML's output: a point, its margin round it.
        if ([] === $git->nodes) {
            return PlantUmlFrame::drawing($git->document, [], 1.0, 1.0);
        }
        $nodes = [];
        $edges = [];
        foreach ($git->nodes as $index => $node) {
            [$width, $height] = self::size($node);
            $nodes[] = new LayoutNode('n'.$index, '', $width, $height, order: $index);
        }
        foreach ($git->nodes as $index => $node) {
            // As dot keeps a node's edges, by the node at their other end, in the order it was made.
            $parents = \array_map(static fn (GitNode $parent): int => (int) \array_search($parent, $git->nodes, strict: true), $node->down);
            \sort($parents);
            foreach ($parents as $parent) {
                $edges[] = new LayoutEdge(\count($edges), 'n'.$index, 'n'.$parent);
            }
        }
        $placing = new DotEngine()->place(new LayoutGraph($nodes, $edges, spacing: new Spacing(self::RANK_SEPARATION, self::NODE_SEPARATION, 0.0)));
        yield from $placing;
        $placement = $placing->getReturn();
        $boxes = \array_map(static fn (LayoutNode $node): array => [$placement->endpoints[$node->id]->x - ($node->width / 2), $placement->endpoints[$node->id]->y - ($node->height / 2)], $nodes);
        $left = \min(\array_column($boxes, 0));
        // As `SmetanaForGit`'s mirror, which takes the top from the highest centre a whole box's height up.
        $top = \min(\array_map(static fn (LayoutNode $node): float => $placement->endpoints[$node->id]->y - $node->height, $nodes));
        $marks = [];
        [$right, $bottom] = [0.0, 0.0];
        foreach ($git->nodes as $index => $node) {
            [$x, $y] = [$boxes[$index][0] - $left, $boxes[$index][1] - $top];
            \array_push($marks, ...self::box($node, $x, $y, $nodes[$index]->width, $nodes[$index]->height, $line, $font));
            [$right, $bottom] = [\max($right, $x + $nodes[$index]->width), \max($bottom, $y + $nodes[$index]->height)];
        }
        foreach ($edges as $edge) {
            $from = $placement->endpoints[$edge->from];
            $to = $placement->endpoints[$edge->to];
            $points = BoxClip::clipped($placement->splines[$edge->index] ?? [[$from->x, $from->y], [$to->x, $to->y]], $from, $to);
            $points = \array_map(static fn (array $point): array => [$point[0] - $left, $point[1] - $top], $points);
            [$kept, $tip] = Route::shorten($points, self::ARROW);
            $marks[] = new Mark(MarkKind::Polyline, $kept, stroke: $line, dashed: true, strokeWidth: 1.0, dashes: [2.0, 2.0]);
            $marks[] = new Mark(MarkKind::Polygon, PlantUmlArrow::head($kept[\count($kept) - 1], $tip), stroke: $line, fill: $line, strokeWidth: 1.0);
        }

        // As PlantUML sizes it, as measured on its output: rounded down to whole points, and 1 more.
        return PlantUmlFrame::roundedDown($git->document, $marks, $right, $bottom, past: 1.0);
    }

    /** @return array{float, float} as `MagicBox`'s `getBigDim`, rounded as dot rounds a node's size */
    private static function size(GitNode $node): array
    {
        [$commentWidth, $commentHeight] = self::comment($node);
        [$namesWidth, $namesHeight] = self::namesSize($node);

        return [\round(\max($commentWidth, $namesWidth) + 8), \max(self::LOWEST, \round($commentHeight + $namesHeight + 2))];
    }

    /** @return array{float, float} its comment's size, which only a top node shows */
    private static function comment(GitNode $node): array
    {
        if (null === $node->comment || !$node->top()) {
            return [0.0, 0.0];
        }

        return [new PlantUmlText([$node->comment], self::COMMENT)->size()[0], self::COMMENT * self::SANS];
    }

    /** Its commits' names, in bold, in the monospaced font PlantUML writes them in. */
    private static function font(string $color = '#000000'): CreoleFont
    {
        return new CreoleFont(self::NAME, 'monospaced', bold: true, color: $color);
    }

    /** @return array{float, float} its names' size, a monospaced line each, as Java measures the font */
    private static function namesSize(GitNode $node): array
    {
        $font = self::font();

        return [\max(0.0, ...\array_map($font->width(...), $node->texts)), \count($node->texts) * $font->line()];
    }

    /**
     * As `MagicBox`'s `drawBorder`: the comment at the node's top left, the box below it, the
     * names centred in the box.
     *
     * @return list<Mark>
     */
    private static function box(GitNode $node, float $x, float $y, float $width, float $height, string $line, string $font): array
    {
        $below = self::comment($node)[1];
        $boxHeight = $height - $below;
        $marks = [new Mark(MarkKind::Polygon, RoundedBox::points($x, $y + $below, $width, $boxHeight, 4.0), stroke: $line, fill: 'none', strokeWidth: 1.5)];
        if ($below > 0) {
            $comment = new PlantUmlText([(string) $node->comment], self::COMMENT, color: $font);
            \array_push($marks, ...$comment->marks($x, $y, $comment->size()[0], 'left'));
        }
        [$namesWidth, $namesHeight] = self::namesSize($node);
        $names = self::font($font);
        $top = $y + $below + (($boxHeight - $namesHeight) / 2);
        foreach ($node->texts as $index => $text) {
            // Each line centred, standing its descent above its foot.
            $left = $x + (($width - $namesWidth) / 2) + (($namesWidth - $names->width($text)) / 2);
            \array_push($marks, ...$names->marks($text, $left, $top + (($index + 1) * $names->line()) - $names->descent()));
        }

        return $marks;
    }
}
