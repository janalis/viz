<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/**
 * An element and what hangs under it, as PlantUML's `ITFLeaf` and `ITFComposed` lay them out:
 * its main box — its text padded in a box, its text alone, or nothing — then its children on
 * its left and on its right, each its style's bottom margin under the one before, 10 aside
 * from a line down from its box's middle that a line across joins each to; under `Width
 * auto`, its children's boxes as wide as the widest.
 */
final class WbsBlock
{
    private const float DELTA = 10.0;

    /** @var list<self> */
    public readonly array $left;
    /** @var list<self> */
    public readonly array $right;
    private float $forced = 0.0;
    private ?Style $style = null;
    private ?PlantUmlText $text = null;

    public function __construct(
        public readonly WbsElement $element,
        private readonly StyleSheet $sheet,
    ) {
        $this->left = \array_map(static fn (WbsElement $child): self => new self($child, $sheet), $element->leaf() ? [] : $element->left);
        $this->right = \array_map(static fn (WbsElement $child): self => new self($child, $sheet), $element->leaf() ? [] : $element->right);
    }

    /**
     * Its children's boxes as wide as the widest, when its style's `Width` is `auto`; theirs in
     * turn. The root's, as PlantUML's `Fork` keeps them, are all on its right.
     */
    public function autoWidth(): void
    {
        $children = 0 === $this->element->level ? $this->right : [...$this->left, ...$this->right];
        if ('auto' === \strtolower($this->style()->value('Width'))) {
            $widest = \array_reduce($children, static fn (float $widest, self $child): float => \max($widest, $child->mainWidth()), initial: 0.0);
            foreach ($children as $child) {
                $child->forced = $widest;
            }
        }
        foreach ($children as $child) {
            $child->autoWidth();
        }
    }

    /** @return array{float, float} */
    public function size(): array
    {
        [$mainWidth, $mainHeight] = $this->main();
        if ($this->element->leaf()) {
            return [$mainWidth, $mainHeight];
        }

        return [
            \max($mainWidth / 2, self::DELTA + self::width($this->left)) + \max($mainWidth / 2, self::DELTA + self::width($this->right)),
            $mainHeight + \max($this->height($this->left), $this->height($this->right)),
        ];
    }

    /** Where the line down from it meets it, across from its left. */
    public function middle(): float
    {
        if ($this->element->leaf()) {
            return $this->size()[0] / 2;
        }

        return \max($this->main()[0] / 2, self::DELTA + self::width($this->left));
    }

    /** @return array{float, float} where a line from its left reaches it: its main box's left side, half way down */
    public function f1(): array
    {
        [$width, $height] = $this->main();

        return $this->element->leaf() ? [0.0, $this->size()[1] / 2] : [$this->middle() - ($width / 2), $height / 2];
    }

    /** @return array{float, float} where a line from its right reaches it */
    public function f2(): array
    {
        [$width, $height] = $this->main();

        return $this->element->leaf() ? [$this->size()[0], $this->size()[1] / 2] : [$this->middle() + ($width / 2), $height / 2];
    }

    /**
     * @param array<int, array{float, float, float, float}> $geometry where each element is drawn, by its object id, as PlantUML records it for the arrows between elements
     * @return list<Mark> its box, then each child and the line to it, then the line down
     */
    public function marks(float $x, float $y, array &$geometry): array
    {
        [$mainWidth, $mainHeight] = $this->main();
        if ($this->element->leaf()) {
            [$width, $height] = $this->size();
            $geometry[\spl_object_id($this->element)] = [$x, $y, $width, $height];

            return $this->mainMarks($x, $y);
        }
        // As PlantUML records it: at the block's corner, as large as its box.
        $geometry[\spl_object_id($this->element)] = [$x, $y, $mainWidth, $mainHeight];
        $middle = $x + $this->middle();
        $marks = $this->mainMarks($middle - ($mainWidth / 2), $y);
        $margin = $this->margin();
        $top = $y + $mainHeight;
        $low = $top;
        foreach ($this->left as $child) {
            $top += $margin;
            [$childWidth, $childHeight] = $child->size();
            $low = $top + $child->f2()[1];
            $left = $middle - $childWidth - self::DELTA;
            \array_push($marks, ...$this->line($left + $child->f2()[0], $low, $middle, $low), ...$child->marks($left, $top, $geometry));
            $top += $childHeight;
        }
        $top = $y + $mainHeight;
        $lowRight = $top;
        foreach ($this->right as $child) {
            $top += $margin;
            $childHeight = $child->size()[1];
            $lowRight = $top + $child->f1()[1];
            \array_push($marks, ...$this->line($middle, $lowRight, $middle + self::DELTA + $child->f1()[0], $lowRight), ...$child->marks($middle + self::DELTA, $top, $geometry));
            $top += $childHeight;
        }

        return [...$marks, ...$this->line($middle, $y + $mainHeight, $middle, \max($low, $lowRight))];
    }

    /** @return list<Mark> a line in its arrow style, as PlantUML's `drawLine` */
    public function line(float $x1, float $y1, float $x2, float $y2): array
    {
        $style = $this->sheet->style(['root', 'element', 'wbsDiagram', 'arrow'], $this->stereotypes(), $this->element->level);
        $color = $style->color('LineColor', '#181818');
        if ('none' === $color) {
            return [];
        }

        return [new Mark(
            MarkKind::Polyline,
            [[\min($x1, $x2), $y1], [\max($x1, $x2), $y2]],
            stroke: $color,
            strokeWidth: $style->number('LineThickness', 1.0),
            dashed: null !== $style->dashes(),
            dashes: $style->dashes(),
        )];
    }

    /** @return array{float, float} its main box's size */
    public function main(): array
    {
        if (WbsShape::Pseudo === $this->element->shape) {
            return [$this->element->leaf() ? $this->forced : 0.0, 0.0];
        }
        [$width, $height] = $this->text()->size();
        if (WbsShape::None === $this->element->shape) {
            // Its text, 3 after it and 1 above and below; a leaf as wide as it is forced to be.
            return [$this->element->leaf() ? \max($width + 3, $this->forced) : $width + 3, $height + 2];
        }
        [$top, $right, $bottom, $left] = $this->style()->sides('Padding');

        return [\max($width + $left + $right, $this->style()->number('MinimumWidth'), $this->forced), $height + $top + $bottom];
    }

    /** @return list<Mark> its main box, its top left at a point */
    public function mainMarks(float $x, float $y): array
    {
        $text = $this->text();
        [$textWidth] = $text->size();
        [$width, $height] = $this->main();
        if (WbsShape::Pseudo === $this->element->shape) {
            return [];
        }
        if (WbsShape::None === $this->element->shape) {
            return $text->marks($x + (($width - $textWidth - 3) / 2), $y + 1, $textWidth, 'left');
        }
        $style = $this->style();
        [$top, $right, , $left] = $style->sides('Padding');
        $box = ClassBox::rectangle(
            $x,
            $y,
            $width,
            $height,
            $style->color('LineColor', '#181818'),
            $style->color('BackGroundColor', '#f1f1f1'),
            $style->number('LineThickness', 1.5),
            $style->number('RoundCorner'),
        );

        return [...Shadow::cast([$box], $style->number('Shadowing')), ...$text->marks($x + $left, $y + $top, $width - $left - $right, \strtolower($style->value('HorizontalAlignment', 'left')))];
    }

    /** The width of its main box, as its parent widens its children's under `Width auto`. */
    private function mainWidth(): float
    {
        return $this->element->leaf() ? $this->size()[0] : $this->main()[0];
    }

    private function margin(): float
    {
        return WbsShape::Pseudo === $this->element->shape ? 0.0 : $this->style()->sides('Margin')[2];
    }

    /** @param list<self> $blocks */
    private static function width(array $blocks): float
    {
        return \array_reduce($blocks, static fn (float $width, self $block): float => \max($width, $block->size()[0]), initial: 0.0);
    }

    /** @param list<self> $blocks */
    private function height(array $blocks): float
    {
        $margin = $this->margin();

        return \array_reduce($blocks, static fn (float $height, self $block): float => $height + $margin + $block->size()[1], initial: 0.0);
    }

    private function text(): PlantUmlText
    {
        return $this->text ??= PlantUmlText::styled($this->element->label, $this->style());
    }

    /** As PlantUML's `WElement`: a node of the structure, the root's or a leaf's, boxless or not, at its depth, its colour over it. */
    private function style(): Style
    {
        if (null !== $this->style) {
            return $this->style;
        }
        $names = ['root', 'element', 'wbsDiagram', 'node'];
        $boxless = WbsShape::None === $this->element->shape;
        $names = [
            ...$names,
            ...match (true) {
                0 === $this->element->level => $boxless ? ['rootNode', 'boxless'] : ['rootNode'],
                $this->element->leaf() => $boxless ? ['leafNode', 'boxless'] : ['leafNode'],
                $boxless => ['boxless'],
                default => [],
            },
        ];
        $style = $this->sheet->style($names, $this->stereotypes(), $this->element->level);
        $color = null === $this->element->color ? null : PlantUmlColor::parse($this->element->color);

        return $this->style = null === $color ? $style : $style->withValues([...$style->values, 'BackGroundColor' => $color]);
    }

    /** @return list<string> */
    private function stereotypes(): array
    {
        return null === $this->element->stereotype ? [] : ClassReader::stereotypes($this->element->stereotype);
    }
}
