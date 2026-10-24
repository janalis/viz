<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A timing diagram drawn as PlantUML's `TimingDiagram` draws it: each player under the last,
 * its title in a tab, its states' names on the left and its panel along the time axis; a
 * frame round them unless compact; the time grid dashed down them, the axis below; messages
 * as arrows between moments; highlights shaded behind and dashed in front. 10 round it all.
 */
final class TimingDrawing
{
    private const float MARGIN_X = 5.0;
    private const float AROUND = 10.0;

    /** @var array<int, TimingPanel> by player */
    private array $panels = [];
    private TimingParts $parts;
    private Style $root;

    private function __construct(
        private readonly PlantUmlTiming $diagram,
    ) {
        $diagram->ruler->ensureNotEmpty();
        $this->parts = new TimingParts($diagram->ruler, $diagram->document->styles);
        $this->root = $diagram->document->styles->style(['root', 'element', 'timingDiagram']);
        foreach ($diagram->players as $player) {
            $this->panels[\spl_object_id($player)] = match ($player->kind) {
                'robust' => new TimingRobust($player, $this->parts),
                'binary' => new TimingBinary($player, $this->parts),
                'clock' => new TimingClock($player, $this->parts),
                default => new TimingState($player, $this->parts, 'rectangle' === $player->kind),
            };
        }
    }

    public static function draw(PlantUmlTiming $diagram): Drawing
    {
        // Read before its panels are made, which set the axis's moments.
        $unwritten = $diagram->ruler->empty();
        $drawing = new self($diagram);
        [$marks, $width, $height] = $drawing->paint();
        $marks = \array_map(static fn (Mark $mark): Mark => Units::moved($mark, self::AROUND, self::AROUND), $marks);
        // As PlantUML, as large as its block and 10 round it, or as far as anything is drawn, then to the next whole point.
        [$right, $bottom] = [$width + (4 * self::AROUND), $height + (4 * self::AROUND)];
        foreach ($marks as $mark) {
            // A text reaches as far as its characters' room, and no further down than its baseline.
            $points = MarkKind::Text === $mark->kind ? [[$mark->points[0][0] + (\array_sum($mark->slots ?? []) / 2), $mark->points[0][1]]] : $mark->points;
            foreach ($points as [$x, $y]) {
                [$right, $bottom] = [\max($right, $x + self::AROUND), \max($bottom, $y + self::AROUND)];
            }
        }
        // With no moment written, PlantUML is seen to keep room for a tick more than it draws.
        $right += $unwritten ? $diagram->ruler->interval() : 0.0;

        return PlantUmlFrame::drawing($diagram->document, $marks, \floor($right) + 1 - (2 * self::AROUND), \floor($bottom) + 1 - (2 * self::AROUND));
    }

    /** @return array{list<Mark>, float, float} */
    private function paint(): array
    {
        $diagram = $this->diagram;
        $ruler = $diagram->ruler;
        $part = $this->part();
        $width = $part + $ruler->width() + (2 * self::MARGIN_X);
        $inner = $this->inner();
        $line = $this->root->color('LineColor', '#333333');
        $thickness = $this->root->number('LineThickness', 0.5);
        $axisX = self::MARGIN_X + $part;
        $marks = [];
        $top = 0.0;
        foreach ($diagram->players as $player) {
            $panel = $this->panels[\spl_object_id($player)];
            $background = $player->background;
            if (null !== $background) {
                $marks[] = new Mark(
                    MarkKind::Polygon,
                    [[0.0, $top], [$width, $top], [$width, $top + $this->frame($player) + $panel->height()], [0.0, $top + $this->frame($player) + $panel->height()]],
                    stroke: $background,
                    fill: $background,
                    strokeWidth: 1.0,
                );
            }
            $top += $this->frame($player) + $panel->height();
        }
        if (!$diagram->compact) {
            $marks[] = self::line(0.0, 0.0, 0.0, $inner, $line, $thickness);
            $marks[] = self::line($width, 0.0, $width, $inner, $line, $thickness);
        }
        $highlight = $diagram->document->styles->style(['root', 'element', 'timingDiagram', 'highlight']);
        foreach ($diagram->highlights as $span) {
            [$from, $to] = [$axisX + $ruler->x($span->from->time), $axisX + $ruler->x($span->to->time)];
            $back = $span->back ?? $highlight->color('BackGroundColor', '#eeeeee');
            $marks[] = new Mark(MarkKind::Polygon, [[$from, 0.0], [$to, 0.0], [$to, $inner], [$from, $inner]], stroke: 'none', fill: $back, strokeWidth: 0.0);
        }
        $grid = $diagram->document->styles->style(['root', 'element', 'timingDiagram', 'timegrid']);
        for ($tick = 0, $ticks = $ruler->ticks(); $tick <= $ticks; ++$tick) {
            $x = $axisX + ($ruler->interval() * $tick);
            $marks[] = new Mark(MarkKind::Polyline, [[$x, 0.0], [$x, $inner]], stroke: $grid->color('LineColor', '#333333'), dashed: true, strokeWidth: 0.5, dashes: [3.0, 5.0]);
        }
        $top = 0.0;
        foreach ($diagram->players as $player) {
            $panel = $this->panels[\spl_object_id($player)];
            if (!$player->compact) {
                $marks[] = self::line(0.0, $top, $width, $top, $line, $thickness);
            }
            \array_push($marks, ...$this->title($player, $top));
            $panelTop = $top + $this->frame($player);
            \array_push($marks, ...$panel->left(self::MARGIN_X, $panelTop, $part), ...$panel->right($axisX, $panelTop));
            $top = $panelTop + $panel->height();
        }
        \array_push($marks, ...$this->axis($axisX, $inner));
        foreach ($diagram->messages as $message) {
            \array_push($marks, ...$this->message($message, $axisX));
        }
        foreach ($diagram->highlights as $span) {
            [$from, $to] = [$axisX + $ruler->x($span->from->time), $axisX + $ruler->x($span->to->time)];
            $color = $span->line ?? $highlight->color('LineColor', '#333333');
            foreach ([$from, $to] as $x) {
                $marks[] = new Mark(
                    MarkKind::Polyline,
                    [[$x, 0.0], [$x, $inner]],
                    stroke: $color,
                    dashed: null !== $highlight->dashes(),
                    strokeWidth: $highlight->number('LineThickness', 2.0),
                    dashes: $highlight->dashes(),
                );
            }
            \array_push($marks, ...new TimingText($span->caption, $diagram->document->styles->style(['root', 'element', 'timingDiagram', 'robust']), '#000000')->marks($from + 3, 2.0));
        }
        $axisHeight = $this->parts->text('0', 'timeline')->size()[1];

        return [$marks, $width, $inner + $axisHeight];
    }

    /** @return list<Mark> PlantUML's `PlayerFrame`: the title, and, unless compact, a tab round it */
    private function title(TimingPlayer $player, float $top): array
    {
        if ('' === \trim($player->title)) {
            return [];
        }
        $text = $this->parts->text($player->title);
        [$width, $height] = $text->size();
        $marks = $text->marks(self::MARGIN_X, $top);
        if ($player->compact) {
            return $marks;
        }
        $line = $this->root->color('LineColor', '#333333');
        $thickness = $this->root->number('LineThickness', 0.5);
        [$right, $bottom] = [self::MARGIN_X + $width + 1, $top + $height + 1];

        return [...$marks, self::line(0.0, $bottom, $right, $bottom, $line, $thickness), self::line($right, $bottom, $right + 10, $top, $line, $thickness)];
    }

    /** @return list<Mark> PlantUML's `TimingRuler.drawTimeAxis` */
    private function axis(float $x, float $y): array
    {
        $diagram = $this->diagram;
        $ruler = $diagram->ruler;
        if ('hidden' === $diagram->axis) {
            return [];
        }
        $style = $diagram->document->styles->style(['root', 'element', 'timingDiagram', 'timeline']);
        [$color, $thickness] = [$style->color('LineColor', '#333333'), $style->number('LineThickness', 2.0)];
        $first = $ruler->x($ruler->firstNotNegative());
        $marks = [];
        $count = 0;
        while (($first + ($count * $ruler->interval())) <= $ruler->width()) {
            if ('automatic' === $diagram->axis) {
                $at = $x + $first + ($count * $ruler->interval());
                $marks[] = self::line($at, $y, $at, $y + 5, $color, $thickness);
            }
            ++$count;
        }
        $marks[] = self::line($x + $first, $y, $x + $first + (($count - 1) * $ruler->interval()), $y, $color, $thickness);
        foreach ($this->labels() as [$time, $label]) {
            if ('manual' === $diagram->axis) {
                $marks[] = self::line($x + $ruler->x($time), $y, $x + $ruler->x($time), $y + 5, $color, $thickness);
            }
            $text = $this->parts->text($label, 'timeline');
            \array_push($marks, ...$text->marks($x + $ruler->x($time) - ($text->size()[0] / 2), $y + 6));
        }

        return $marks;
    }

    /** @return list<array{float, string}> the moments the axis writes, and how: each moment named or written; or, scaled, each tick */
    private function labels(): array
    {
        $diagram = $this->diagram;
        $ruler = $diagram->ruler;
        if ('manual' === $diagram->axis) {
            $names = [];
            foreach ($diagram->codes as $name => $tick) {
                $names[(string) $tick->time] ??= $name;
            }

            return \array_map(static fn (float $time): array => [$time, $names[(string) $time] ?? TimingTick::written($time, $ruler->format)], $ruler->times());
        }
        $rounds = [];
        if (0 === $ruler->forced()) {
            foreach ($ruler->times() as $time) {
                $rounds[(int) $time] = true;
            }
        }
        if (0 !== $ruler->forced()) {
            for ($tick = 0, $ticks = $ruler->ticks(); $tick <= $ticks; ++$tick) {
                $rounds[($ruler->forced() * $tick) + (int) $ruler->min()] = true;
            }
        }
        \ksort($rounds);
        if ([] !== $rounds && \array_key_first($rounds) < 0 && \array_key_last($rounds) > 0) {
            $rounds[0] = true;
            \ksort($rounds);
        }

        return \array_map(static fn (int $round): array => [(float) $round, TimingTick::written((float) $round, $ruler->format)], \array_keys($rounds));
    }

    /**
     * PlantUML's `TimeArrow`: the shortest line between where the two moments stand, a head 8
     * long at 20° either side, its text by the head, above it when the arrow goes down.
     *
     * @return list<Mark>
     */
    private function message(TimingMessage $message, float $x): array
    {
        if (null === $message->fromTick || null === $message->toTick) {
            return [];
        }
        $from = $this->panels[\spl_object_id($message->from)]->projection($message->fromTick);
        $to = $this->panels[\spl_object_id($message->to)]->projection($message->toTick);
        if (null === $from || null === $to) {
            return [];
        }
        [$fromTop, $toTop] = [$this->panelTop($message->from), $this->panelTop($message->to)];
        $best = null;
        foreach ($from as [$fromX, $fromY]) {
            foreach ($to as [$toX, $toY]) {
                $candidate = [[$x + $fromX, $fromTop + $fromY], [$x + $toX, $toTop + $toY]];
                if (null === $best || self::length($candidate) < self::length($best)) {
                    $best = $candidate;
                }
            }
        }
        [[$startX, $startY], [$endX, $endY]] = $best;
        $style = $this->diagram->document->styles->style(['root', 'element', 'timingDiagram', 'arrow']);
        $color = $message->color ?? $style->color('LineColor', '#00008b');
        $thickness = $style->number('LineThickness', 1.5);
        $angle = \atan2($endX - $startX, $endY - $startY);
        $delta = \deg2rad(20.0);
        $one = [$endX - (\sin($angle + $delta) * 8), $endY - (\cos($angle + $delta) * 8)];
        $two = [$endX - (\sin($angle - $delta) * 8), $endY - (\cos($angle - $delta) * 8)];
        $text = new TimingText($message->label, $style);
        $textY = (($one[1] + $two[1]) / 2) - ($startY < $endY ? $text->size()[1] : 0.0);

        return [
            new Mark(MarkKind::Polyline, [[$startX, $startY], [$endX, $endY]], stroke: $color, strokeWidth: $thickness),
            new Mark(MarkKind::Polygon, [$one, $two, [$endX, $endY]], stroke: $color, fill: $color, strokeWidth: $thickness),
            ...$text->marks(($one[0] + $two[0]) / 2, $textY),
        ];
    }

    /** @param array{array{float, float}, array{float, float}} $line */
    private static function length(array $line): float
    {
        return \hypot($line[1][0] - $line[0][0], $line[1][1] - $line[0][1]);
    }

    private function panelTop(TimingPlayer $target): float
    {
        $top = 0.0;
        foreach ($this->diagram->players as $player) {
            $top += $this->frame($player);
            if ($player === $target) {
                break;
            }
            $top += $this->panels[\spl_object_id($player)]->height();
        }

        return $top;
    }

    /** The widest left panel. */
    private function part(): float
    {
        return \array_reduce($this->panels, static fn (float $width, TimingPanel $panel): float => \max($width, $panel->leftWidth()), initial: 0.0);
    }

    private function inner(): float
    {
        $height = 0.0;
        foreach ($this->diagram->players as $player) {
            $height += $this->frame($player) + $this->panels[\spl_object_id($player)]->height();
        }

        return $height;
    }

    /** As PlantUML's `PlayerFrame.getHeight`: its title's height, 1 more, or 1 less when compact. */
    private function frame(TimingPlayer $player): float
    {
        $height = '' === \trim($player->title) ? 0.0 : $this->parts->text($player->title)->size()[1];

        return $height + ($player->compact ? -1 : 1);
    }

    private static function line(float $x1, float $y1, float $x2, float $y2, string $color, float $width): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: $color, strokeWidth: $width);
    }
}
