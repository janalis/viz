<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\PlantUML\Teoz\Rose\Bezier;

/**
 * An idea and its branches, as PlantUML's `FingerImpl` lays them out: its phalanx — the idea
 * in a box with room above and below, or its text alone — then, its style's margins and 30
 * more past it, its branches stacked as a tetris, each joined to it by a line that eases
 * into a curve; along a length, rightwards or leftwards, or downwards and upwards from top to
 * bottom, and across a thickness.
 */
final class Finger
{
    /** @var list<self> */
    private array $nail;
    private ?Tetris $tetris = null;
    private ?Style $style = null;
    private ?PlantUmlText $text = null;

    public function __construct(
        private readonly MindMapIdea $idea,
        private readonly StyleSheet $sheet,
        /** 1 rightwards or downwards, -1 the other way. */
        private readonly int $direction,
        private readonly bool $vertical,
        public bool $drawPhalanx = true,
    ) {
        $this->nail = \array_map(static fn (MindMapIdea $child): self => new self($child, $sheet, $direction, $vertical), $idea->children);
    }

    public function thickness(): float
    {
        return \max($this->phalanxThickness(), $this->tetris()->height());
    }

    public function elongation(): float
    {
        return $this->phalanxElongation() + $this->tetris()->width();
    }

    /** The room between the phalanx and its branches: its style's margin before them, and after them with 30 more — 5 from top to bottom. */
    public function x12(): float
    {
        return $this->x1() + $this->x2();
    }

    /**
     * @param array{float, float} $origin where the phalanx's middle starts, in points
     * @return list<Mark> the phalanx, then each branch and the line to it
     */
    public function marks(array $origin): array
    {
        $marks = [];
        if ($this->drawPhalanx) {
            $offset = 1 === $this->direction ? 0.0 : -$this->phalanxElongation();
            [$x, $y] = $this->at($origin, $offset, -$this->phalanxThickness() / 2);
            \array_push($marks, ...$this->phalanxMarks($x, $y));
        }
        $start = $this->direction * $this->phalanxElongation();
        $end = $this->direction * ($this->phalanxElongation() + $this->x12());
        $arrow = $this->arrow();
        $color = $arrow->color('LineColor', '#181818');
        foreach ($this->nail as $index => $child) {
            $middle = $this->tetris()->middles()[$index];
            \array_push($marks, ...$child->marks($this->at($origin, $end, $middle)));
            if ('none' === $color) {
                continue;
            }
            $marks[] = new Mark(
                MarkKind::Polyline,
                $this->line($origin, $start, $end, $middle),
                stroke: $color,
                strokeWidth: $arrow->number('LineThickness', 1.0),
                dashed: null !== $arrow->dashes(),
                dashes: $arrow->dashes(),
            );
        }

        return $marks;
    }

    /**
     * The line from the phalanx to a branch: straight for 10 — 3 from top to bottom — then a
     * curve, its controls 25 — 10 — from its ends, then straight again.
     *
     * @param array{float, float} $origin
     * @return list<array{float, float}>
     */
    private function line(array $origin, float $start, float $end, float $middle): array
    {
        [$straight, $control] = $this->vertical ? [3.0 * $this->direction, 10.0 * $this->direction] : [10.0 * $this->direction, 25.0 * $this->direction];

        return [
            $this->at($origin, $start, 0.0),
            ...Bezier::points(
                $this->at($origin, $start + $straight, 0.0),
                $this->at($origin, $start + $control, 0.0),
                $this->at($origin, $end - $control, $middle),
                $this->at($origin, $end - $straight, $middle),
            ),
            $this->at($origin, $end, $middle),
        ];
    }

    /**
     * A point at a length and a thickness from an origin.
     *
     * @param array{float, float} $origin
     * @return array{float, float}
     */
    private function at(array $origin, float $length, float $across): array
    {
        return $this->vertical ? [$origin[0] + $across, $origin[1] + $length] : [$origin[0] + $length, $origin[1] + $across];
    }

    /**
     * As a tee for its parent's tetris: the phalanx and the room after it when it has branches, then those.
     *
     * @return array{float, float, float, float}
     */
    private function tee(): array
    {
        if ([] === $this->nail) {
            return [$this->phalanxThickness(), $this->phalanxElongation(), 0.0, 0.0];
        }

        return [$this->phalanxThickness(), $this->phalanxElongation() + $this->x1(), $this->tetris()->height(), $this->x2() + $this->tetris()->width()];
    }

    private function tetris(): Tetris
    {
        return $this->tetris ??= new Tetris(\array_map(static fn (self $child): array => $child->tee(), $this->nail));
    }

    private function phalanxThickness(): float
    {
        return $this->phalanx()[$this->vertical ? 0 : 1];
    }

    private function phalanxElongation(): float
    {
        return $this->phalanx()[$this->vertical ? 1 : 0];
    }

    private function x1(): float
    {
        [$top, , , $left] = $this->style()->sides('Margin');

        return $this->vertical ? $top : $left;
    }

    private function x2(): float
    {
        [, $right, $bottom] = $this->style()->sides('Margin');

        return $this->vertical ? $bottom + 5 : $right + 30;
    }

    /**
     * The phalanx's size, and where its box or text stands in it: a box with its margins across,
     * or the text with 3 before it — after it leftwards — and 1 above and below.
     *
     * @return array{float, float, float, float}
     */
    private function phalanx(): array
    {
        if (!$this->drawPhalanx) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        [$width, $height] = $this->text()->size();
        if (!$this->idea->boxed) {
            return [$width + 3, $height + 2, 1 === $this->direction ? 3.0 : 0.0, 1.0];
        }
        [$width, $height] = $this->box();
        [$top, $right, $bottom, $left] = $this->style()->sides('Margin');

        return $this->vertical ? [$width + $left + $right, $height, $left, 0.0] : [$width, $height + $top + $bottom, 0.0, $top];
    }

    /** @return array{float, float} the box: its text padded, at least its style's least width */
    private function box(): array
    {
        [$width, $height] = $this->text()->size();
        [$top, $right, $bottom, $left] = $this->style()->sides('Padding');

        return [\max($width + $left + $right, $this->style()->number('MinimumWidth')), $height + $top + $bottom];
    }

    /** @return list<Mark> */
    private function phalanxMarks(float $x, float $y): array
    {
        $style = $this->style();
        [, , $dx, $dy] = $this->phalanx();
        [$x, $y] = [$x + $dx, $y + $dy];
        $text = $this->text();
        if (!$this->idea->boxed) {
            return $text->marks($x, $y, $text->size()[0], 'left');
        }
        [$boxWidth, $boxHeight] = $this->box();
        [$top, $right, , $left] = $style->sides('Padding');
        $box = ClassBox::rectangle(
            $x,
            $y,
            $boxWidth,
            $boxHeight,
            $style->color('LineColor', '#181818'),
            $style->color('BackGroundColor', '#f1f1f1'),
            $style->number('LineThickness', 1.5),
            $style->number('RoundCorner'),
        );

        return [
            ...Shadow::cast([$box], $style->number('Shadowing')),
            ...$text->marks($x + $left, $y + $top, $boxWidth - $left - $right, \strtolower($style->value('HorizontalAlignment', 'left'))),
        ];
    }

    private function text(): PlantUmlText
    {
        return $this->text ??= PlantUmlText::styled($this->idea->label, $this->style());
    }

    /** As PlantUML's `Idea`: a node of the mind map, the root's or a leaf's, boxless or not, at its depth, its colour over it. */
    private function style(): Style
    {
        if (null !== $this->style) {
            return $this->style;
        }
        $names = ['root', 'element', 'mindmapDiagram', 'node'];
        if (0 === $this->idea->level) {
            $names[] = 'rootNode';
        }
        if (0 !== $this->idea->level && [] === $this->idea->children) {
            $names[] = 'leafNode';
        }
        if (!$this->idea->boxed) {
            $names[] = 'boxless';
        }
        $style = $this->sheet->style($names, $this->stereotypes(), $this->idea->level);
        $color = null === $this->idea->color ? null : PlantUmlColor::parse($this->idea->color);

        return $this->style = null === $color ? $style : new Style([...$style->values, 'BackGroundColor' => $color], $style->sprites);
    }

    private function arrow(): Style
    {
        return $this->sheet->style(['root', 'element', 'mindmapDiagram', 'arrow'], $this->stereotypes(), $this->idea->level);
    }

    /** @return list<string> */
    private function stereotypes(): array
    {
        return null === $this->idea->stereotype ? [] : ClassReader::stereotypes($this->idea->stereotype);
    }
}
