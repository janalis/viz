<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * A state or pseudostate drawn as PlantUML draws it, in points: a state as a rounded box with
 * its name over a line and its description below, at least 50 by 50 (50 by 40 when its empty
 * description is hidden); the start a black disc, the end a ringed one, history a circled H,
 * a choice a diamond, and fork, join and synchronization bars a black bar.
 */
final readonly class StateBox
{
    private const float MARGIN = 5.0;

    private function __construct(
        private StateNode $node,
        private Style $style,
        private Style $circle,
        private PlantUmlText $name,
        private PlantUmlText $description,
        private bool $line,
        public float $width,
        public float $height,
    ) {}

    public static function of(StateNode $node, PlantUmlStates $states): self
    {
        $sheet = $states->document->styles;
        $style = $sheet->style(['root', 'element', 'stateDiagram', 'state'], $node->stereotypes);
        $circle = $sheet->style(['root', 'element', 'stateDiagram', 'circle', 'end' === $node->kind ? 'end' : 'start']);
        $name = PlantUmlText::styled($node->display, $sheet->style(['root', 'element', 'stateDiagram', 'state', 'name'], $node->stereotypes));
        $description = PlantUmlText::styled($node->description, $style);
        $line = !($states->hideEmptyDescription && [] === $node->description);
        [$width, $height] = match ($node->kind) {
            'start' => [20.0, 20.0],
            'end', 'history', 'deephistory' => [22.0, 22.0],
            'choice' => [24.0, 24.0],
            'fork', 'join' => Direction::LeftRight === $states->direction ? [8.0, 80.0] : [80.0, 8.0],
            default => $line ? self::size($name, $description) : self::bare($name),
        };

        return new self($node, $style, $circle, $name, $description, $line, $width, $height);
    }

    /** @return array{float, float} a state's size with its name alone, its empty description hidden */
    private static function bare(PlantUmlText $name): array
    {
        [$nameWidth, $nameHeight] = $name->size();

        return [\max(50.0, $nameWidth + (2 * self::MARGIN)), \max(40.0, $nameHeight + (2 * self::MARGIN))];
    }

    /** @return array{float, float} */
    private static function size(PlantUmlText $name, PlantUmlText $description): array
    {
        [$nameWidth, $nameHeight] = $name->size();
        [$descriptionWidth, $descriptionHeight] = $description->size();

        return [\max(50.0, \max($nameWidth, $descriptionWidth) + (4 * self::MARGIN)), \max(50.0, $nameHeight + $descriptionHeight + (4 * self::MARGIN))];
    }

    /** @return list<Mark> the state with its top left at a point */
    public function marks(float $x, float $y): array
    {
        $line = ClassColors::line($this->node->color) ?? $this->style->color('LineColor', '#181818');
        $back = ClassColors::back($this->node->color) ?? $this->style->color('BackGroundColor', '#f1f1f1');
        $stroke = $this->style->number('LineThickness', 0.5);
        $dark = $this->circle->color('BackGroundColor', '#222222');
        [$cx, $cy] = [$x + ($this->width / 2), $y + ($this->height / 2)];

        return match ($this->node->kind) {
            'start' => [self::disc($cx, $cy, 10.0, $dark, $dark)],
            'end' => [self::disc($cx, $cy, 11.0, $dark, 'none'), self::disc($cx, $cy, 6.0, $dark, $dark)],
            'history', 'deephistory' => [self::disc($cx, $cy, 11.0, $line, $back), ...new PlantUmlText(['history' === $this->node->kind ? 'H' : 'H*'], 12.0)->marks($x, $cy - 7.5, $this->width)],
            'choice' => [new Mark(MarkKind::Polygon, [[$cx, $y], [$x + $this->width, $cy], [$cx, $y + $this->height], [$x, $cy]], stroke: $line, fill: $back, strokeWidth: $stroke)],
            'fork', 'join' => [new Mark(MarkKind::Polygon, ClassBox::corners($x, $y, $this->width, $this->height), stroke: $dark, fill: $dark, strokeWidth: 1.0)],
            default => $this->state($x, $y, $line, $back, $stroke),
        };
    }

    /** @return list<Mark> */
    private function state(float $x, float $y, string $line, string $back, float $stroke): array
    {
        [, $nameHeight] = $this->name->size();
        $marks = Shadow::cast([ClassBox::rectangle($x, $y, $this->width, $this->height, $line, $back, $stroke, $this->style->number('RoundCorner', 25.0))], $this->style->number('Shadowing'));
        if (!$this->line) {
            return [...$marks, ...$this->name->marks($x, $y + (($this->height - $nameHeight) / 2), $this->width)];
        }
        $rule = $y + self::MARGIN + $nameHeight + self::MARGIN;
        $marks[] = new Mark(MarkKind::Polyline, [[$x, $rule], [$x + $this->width, $rule]], stroke: $line, strokeWidth: $stroke);

        return [...$marks, ...$this->name->marks($x, $y + self::MARGIN, $this->width), ...$this->description->marks($x + self::MARGIN, $rule + self::MARGIN, $this->width, 'left')];
    }

    public static function disc(float $x, float $y, float $radius, string $stroke, string $fill): Mark
    {
        $points = [];
        for ($step = 0; $step < 24; ++$step) {
            $points[] = [$x + ($radius * \cos(($step * \M_PI) / 12)), $y + ($radius * \sin(($step * \M_PI) / 12))];
        }

        return new Mark(MarkKind::Polygon, $points, stroke: $stroke, fill: $fill, strokeWidth: 1.0);
    }
}
