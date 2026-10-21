<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Descriptions\DescriptionElement;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * A network diagram laid out and drawn as PlantUML's `NwDiagram` does: a grid of a line per
 * network and a column per server, each server in the line of its first network and the
 * column its box of columns takes; each line at least 50 tall, each column as wide as its
 * widest cell; each network a thin tube across the columns it reaches, its name and address
 * on its left; groups shaded behind their servers. 5 clear all round, with no other margin.
 */
final class NwDrawing
{
    /** A network tube's thickness. */
    public const float THIN = 5.0;
    private const float MARGIN = 5.0;
    private const float MINIMUM_WIDTH = 70.0;

    /** The symbols PlantUML's `USymbols` draws for a server's `shape`. */
    private const array SHAPES = [
        'actor',
        'agent',
        'artifact',
        'boundary',
        'card',
        'cloud',
        'collections',
        'component',
        'control',
        'database',
        'entity',
        'file',
        'folder',
        'frame',
        'hexagon',
        'interface',
        'label',
        'node',
        'package',
        'person',
        'process',
        'queue',
        'rectangle',
        'stack',
        'storage',
        'usecase',
    ];

    /** @var list<list<?NwCell>> by line, by column */
    private array $grid = [];

    private function __construct(
        private readonly PlantUmlNwDiag $diagram,
    ) {}

    /** @throws Unsupported on a shape the native renderer does not draw */
    public static function draw(PlantUmlNwDiag $diagram): Drawing
    {
        $drawing = new self($diagram);
        $drawing->build();
        [$marks, $width, $height] = $drawing->paint();

        return PlantUmlFrame::bare($diagram->document, $marks, $width, $height);
    }

    private function build(): void
    {
        $diagram = $this->diagram;
        $sheet = $diagram->document->styles;
        $diagram->field->group($diagram->groups, $diagram->servers);
        $columns = $diagram->field->layout();
        $this->grid = \array_fill(0, \count($diagram->networks), \array_fill(0, \count($diagram->servers), value: null));
        foreach ($diagram->networks as $line => $network) {
            foreach ($diagram->servers as $server) {
                $column = $columns[\spl_object_id($server->bar)] ?? null;
                if ($server->main() !== $network || null === $column) {
                    continue;
                }
                $links = [];
                foreach ($diagram->networks as $other) {
                    $address = $server->address($other);
                    if (null !== $address) {
                        $links[] = [$other, $address];
                    }
                }
                $topMargin = NwCell::MAGIC;
                $group = $this->groupOf($server);
                if (null !== $group) {
                    $topMargin += $this->header($group)?->size()[1] ?? 0.0;
                }
                $this->grid[$line][$column] = new NwCell($server, $this->box($server), $links, $diagram->networks, $topMargin, $sheet);
            }
        }
    }

    /** @return array{list<Mark>, float, float} */
    private function paint(): array
    {
        $diagram = $this->diagram;
        $sheet = $diagram->document->styles;
        $networkStyle = $sheet->style(['root', 'element', 'nwdiagDiagram', 'network']);
        $labels = [];
        $labelWidth = 0.0;
        $shift = 0.0;
        foreach ($diagram->networks as $index => $network) {
            $text = PlantUmlText::styled([...ClassReader::split($network->displayName()), ...(null === $network->address ? [] : [$network->address])], $networkStyle);
            [$width, $height] = $text->size();
            // As PlantUML, a network with no name still takes a line.
            $height = $text->empty() ? $text->line() : $height;
            if (0 === $index) {
                $shift = ($height - self::THIN) / 2;
            }
            $labelWidth = \max($labelWidth, $width);
            $labels[] = $text;
        }
        $marks = [];
        $y = self::MARGIN;
        foreach ($labels as $index => $text) {
            [$width] = $text->size();
            \array_push($marks, ...$text->marks(self::MARGIN + $labelWidth - $width, $y, $width, 'right'));
            $y += $this->lineHeight($index);
        }
        [$gridX, $gridY] = [self::MARGIN + $labelWidth + 5, self::MARGIN + $shift];
        \array_push($marks, ...$this->grid($gridX, $gridY));
        [$gridWidth, $gridHeight] = $this->gridSize();
        // As PlantUML, a point past the grid, 5 more past it, marks how far the diagram reaches.
        [$width, $height] = [$gridX + $gridWidth + self::MARGIN + 1, $gridY + $gridHeight + self::MARGIN + 1];
        foreach ($marks as $mark) {
            foreach ($mark->points as [$x, $markY]) {
                [$width, $height] = [\max($width, $x), \max($height, $markY)];
            }
        }

        return [$marks, $width, $height];
    }

    /** @return list<Mark> the grid with its top left at a point: groups, tubes, links, then servers */
    private function grid(float $x, float $y): array
    {
        $marks = [];
        foreach ($this->diagram->groups as $group) {
            \array_push($marks, ...$this->group($group, $x, $y));
        }
        \array_push($marks, ...$this->tubes($x, $y));
        $color = $this->diagram->document->styles->style(['root', 'element', 'nwdiagDiagram', 'arrow'])->color('LineColor', '#181818');
        foreach ($this->cells($x, $y) as [$cell, $left, , $width, $height]) {
            \array_push($marks, ...$cell->links($left, $width, $height, $color));
        }
        foreach ($this->cells($x, $y) as [$cell, $left, $top, $width, $height]) {
            \array_push($marks, ...$cell->marks($left, $top, $width, $height));
        }

        return $marks;
    }

    /** @return list<array{NwCell, float, float, float, float}> each server's cell: where it stands and its size */
    private function cells(float $x, float $y): array
    {
        $cells = [];
        $top = $y;
        foreach ($this->grid as $line => $row) {
            $left = $x;
            $height = $this->lineHeight($line);
            foreach ($row as $column => $cell) {
                $width = $this->columnWidth($column);
                if (null !== $cell) {
                    $cells[] = [$cell, $left, $top, $width, $height];
                }
                $left += $width;
            }
            $top += $height;
        }

        return $cells;
    }

    /** @return list<Mark> each network's tube across the columns it reaches — or the whole width — at its line's top */
    private function tubes(float $x, float $y): array
    {
        $style = $this->diagram->document->styles->style(['root', 'element', 'nwdiagDiagram', 'network']);
        $marks = [];
        $top = $y;
        foreach ($this->diagram->networks as $line => $network) {
            $left = 0.0;
            [$xmin, $xmax] = [$network->fullWidth ? 0.0 : -1.0, 0.0];
            foreach (\array_keys($this->grid[$line] ?? []) as $column) {
                $linked = $this->linked($column, $network);
                if ($linked && $xmin < 0) {
                    $xmin = $left;
                }
                $left += $this->columnWidth($column);
                if ($linked || $network->fullWidth) {
                    $xmax = $left;
                }
            }
            [$network->xmin, $network->xmax, $network->y] = [$x + $xmin, $x + $xmax, $top];
            if ($network->visible) {
                $back = null === $network->color ? $style->color('BackGroundColor', '#e2e2f0') : PlantUmlColor::parse($network->color) ?? '#ffffff';
                $tube = ClassBox::rectangle($x + $xmin, $top, \max(self::MINIMUM_WIDTH, $xmax - $xmin), self::THIN, $style->color('LineColor', '#181818'), $back, 1.0, 0.0);
                \array_push($marks, ...Shadow::cast([$tube], $style->number('Shadowing')));
            }
            $top += $this->lineHeight($line);
        }

        return $marks;
    }

    /** @return list<Mark> a group shaded behind its servers' boxes, 5 round, its description above them */
    private function group(NwGroup $group, float $x, float $y): array
    {
        $bounds = null;
        foreach ($this->cells($x, $y) as [$cell, $left, $top, $width, $height]) {
            if (!$group->contains($cell->server->name)) {
                continue;
            }
            $box = $cell->bounds($left, $top, $width, $height);
            $bounds = null === $bounds ? $box : [\min($bounds[0], $box[0]), \min($bounds[1], $box[1]), \max($bounds[2], $box[2]), \max($bounds[3], $box[3])];
        }
        if (null === $bounds) {
            return [];
        }
        $header = $this->header($group);
        if (null !== $header) {
            $bounds[1] -= $header->size()[1];
        }
        $style = $this->diagram->document->styles->style(['root', 'element', 'nwdiagDiagram', 'group']);
        $back = null === $group->color ? $style->color('BackGroundColor', '#e7e7e7') : PlantUmlColor::parse($group->color) ?? '#ffffff';
        $marks = [ClassBox::rectangle(
            $bounds[0],
            $bounds[1],
            $bounds[2] - $bounds[0],
            $bounds[3] - $bounds[1],
            $style->color('LineColor', '#e7e7e7'),
            $back,
            $style->number('LineThickness', 1.0),
            0.0,
        )];
        if (null !== $header) {
            \array_push($marks, ...$header->marks($bounds[0] + 5, $bounds[1], $header->size()[0], 'left'));
        }

        return $marks;
    }

    private function header(NwGroup $group): ?PlantUmlText
    {
        return (
            null === $group->description ? null : PlantUmlText::styled(ClassReader::split($group->description), $this->diagram->document->styles->style(['root', 'element', 'nwdiagDiagram', 'group']))
        );
    }

    private function groupOf(NwServer $server): ?NwGroup
    {
        foreach ($this->diagram->groups as $group) {
            if ($group->contains($server->name)) {
                return $group;
            }
        }

        return null;
    }

    /** A server's symbol, as PlantUML's `asSmall` draws it around its description, in the server style. */
    private function box(NwServer $server): DescriptionBox
    {
        $shape = \strtolower($server->shape);
        if (!\in_array($shape, self::SHAPES, strict: true)) {
            $shape = 'rectangle';
        }
        if (\in_array($shape, ['actor', 'boundary', 'control', 'entity', 'interface', 'usecase', 'person', 'process'], strict: true)) {
            throw new Unsupported('The native PlantUML renderer leaves a network server of shape '.$shape.' to PlantUML');
        }
        $element = new DescriptionElement($server->name, ClassReader::split(\str_replace(', ', replace: "\n", subject: $server->description)), $shape);
        $element->color = $server->color;
        $sheet = $this->diagram->document->styles;

        return DescriptionBox::of($element, $sheet, $sheet->style(['root', 'element', 'nwdiagDiagram', 'server']), flushLeft: true);
    }

    private function linked(int $column, NwNetwork $network): bool
    {
        foreach ($this->grid as $row) {
            if ($row[$column]?->linkedTo($network)) {
                return true;
            }
        }

        return false;
    }

    private function columnWidth(int $column): float
    {
        $width = 0.0;
        foreach ($this->grid as $row) {
            $width = \max($width, $row[$column]?->size()[0] ?? 0.0);
        }

        return $width;
    }

    private function lineHeight(int $line): float
    {
        return \array_reduce($this->grid[$line] ?? [], static fn (float $height, ?NwCell $cell): float => \max($height, $cell?->size()[1] ?? 0.0), initial: 50.0);
    }

    /** @return array{float, float} */
    private function gridSize(): array
    {
        if ([] === $this->grid) {
            return [0.0, 0.0];
        }
        $width = \array_sum(\array_map($this->columnWidth(...), \array_keys($this->grid[0])));
        $height = \array_sum(\array_map($this->lineHeight(...), \array_keys($this->grid)));

        return [\max(self::MINIMUM_WIDTH, $width), $height];
    }
}
