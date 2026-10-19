<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Svek\Svek;
use Janalis\Viz\Source\Unsupported;

/**
 * A native state diagram laid out as svek lays it out and drawn as PlantUML draws it. Each
 * scope is a graph of its own: a composite state no transition crosses into is laid out on
 * its own — each region apart, stacked with dashed lines between them — and stands in its
 * scope as one box with its name and description above; any other composite state is a
 * cluster of its scope's graph. In points, set in the document's frame.
 */
final class StateDrawing
{
    private const float MARGIN = 5.0;

    private function __construct(
        private readonly PlantUmlStates $states,
    ) {}

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlStates $states): \Generator
    {
        $drawing = new self($states);
        $scope = $drawing->scope(null, 0);
        yield from $scope;
        [$marks, $width, $height] = $scope->getReturn();

        return PlantUmlFrame::drawing($states->document, $marks, $width, $height);
    }

    /**
     * A scope laid out and drawn: its marks from the origin, and its size.
     *
     * @return \Generator<int, null, mixed, array{list<Mark>, float, float}>
     */
    private function scope(?string $group, int $region): \Generator
    {
        $svek = new Svek();
        /** @var array<string, StateBox> $boxes */
        $boxes = [];
        /** @var array<string, array{list<Mark>, float, float}> $inner */
        $inner = [];
        $placed = [];
        $clusters = [];
        foreach ($this->members($group, $region) as [$node, $cluster]) {
            $placed[$node->id] = true;
            if (\array_key_exists($node->id, $this->states->composites) && !$this->autarkic($node->id)) {
                if ([] !== $this->states->composites[$node->id]) {
                    throw new Unsupported('The native PlantUML renderer does not draw concurrent regions a transition crosses into');
                }
                [$titleWidth, $titleHeight] = $this->title($node)->size();
                [, $descriptionHeight] = $this->description($node)->size();
                $svek->cluster($node->id, $cluster, $titleWidth + 20, $titleHeight + $descriptionHeight + 15);
                // As svek, a transition to or from a composite state drawn as a cluster ends at a point inside it.
                $svek->node(self::anchor($node->id), 1.0, 1.0, $node->id);
                $clusters[] = $node;
                continue;
            }
            if (\array_key_exists($node->id, $this->states->composites)) {
                $image = $this->composite($node);
                yield from $image;
                $inner[$node->id] = $image->getReturn();
                $svek->node($node->id, $inner[$node->id][1], $inner[$node->id][2], $cluster);
                continue;
            }
            $box = StateBox::of($node, $this->states);
            $boxes[$node->id] = $box;
            $svek->node($node->id, $box->width, $box->height, $cluster);
        }
        $notes = $this->notes($svek, $placed, $group, $region);
        $anchored = \array_map(static fn (StateNode $node): string => $node->id, $clusters);
        $edges = [];
        foreach ($this->states->links as $index => $link) {
            if (!\array_key_exists($link->from, $placed) || !\array_key_exists($link->to, $placed)) {
                continue;
            }
            [$width, $height] = $this->label($index)->size();
            $ends = \array_map(static fn (string $id): string => \in_array($id, $anchored, strict: true) ? self::anchor($id) : $id, [$link->from, $link->to]);
            $edges[$index] = $svek->edge($ends[0], $ends[1], $link->length, $width > 0 ? $width + 8 : 0.0, $height > 0 ? $height + 8 : 0.0, !$link->norank);
        }
        $placing = $svek->place($this->states->direction);
        yield from $placing;
        $placement = $placing->getReturn();

        return [$this->paint($placement, $clusters, $edges, $boxes, $inner, $notes), $placement->graph->width, $placement->graph->height];
    }

    /**
     * The states a scope's graph holds — its own, and those of the composite states drawn as
     * its clusters — each with the cluster that holds it.
     *
     * @return list<array{StateNode, ?string}>
     */
    private function members(?string $group, int $region): array
    {
        $members = [];
        foreach ($this->states->nodes as $node) {
            if ($node->group !== $group || $node->region !== $region) {
                continue;
            }
            $members[] = [$node, null];
            if (\array_key_exists($node->id, $this->states->composites) && !$this->autarkic($node->id)) {
                foreach ($this->members($node->id, 0) as [$inner, $cluster]) {
                    $members[] = [$inner, $cluster ?? $node->id];
                }
            }
        }

        return $members;
    }

    /** Whether no transition joins a state inside a composite state to one outside it. */
    private function autarkic(string $composite): bool
    {
        foreach ($this->states->links as $link) {
            if ($this->inside($link->from, $composite) !== $this->inside($link->to, $composite)) {
                return false;
            }
        }

        return true;
    }

    private function inside(string $id, string $composite): bool
    {
        $group = $this->states->nodes[$id]->group;
        while (null !== $group && $group !== $composite) {
            $group = $this->states->nodes[$group]->group;
        }

        return null !== $group;
    }

    /**
     * A composite state laid out on its own: its regions apart, stacked, under its name and description.
     *
     * @return \Generator<int, null, mixed, array{list<Mark>, float, float}>
     */
    private function composite(StateNode $node): \Generator
    {
        $separators = $this->states->composites[$node->id];
        $across = '||' === ($separators[0] ?? '--');
        $marks = [];
        [$width, $height] = [0.0, 0.0];
        $regions = [];
        for ($index = 0; $index <= \count($separators); ++$index) {
            $scope = $this->scope($node->id, $index);
            yield from $scope;
            $regions[] = $scope->getReturn();
        }
        foreach ($regions as $index => [$regionMarks, $regionWidth, $regionHeight]) {
            [$dx, $dy] = $across ? [$width, 0.0] : [0.0, $height];
            foreach ($regionMarks as $mark) {
                $marks[] = self::moved($mark, $dx, $dy);
            }
            [$width, $height] = $across ? [$width + $regionWidth, \max($height, $regionHeight)] : [\max($width, $regionWidth), $height + $regionHeight];
            $marks = 0 === $index
                ? $marks
                : [...$marks, new Mark(MarkKind::Polyline, $across ? [[$dx, 0.0], [$dx, 0.0]] : [[0.0, $dy], [0.0, $dy]], stroke: '#181818', dashed: true, strokeWidth: 0.5)];
        }
        $stack = $across ? Direction::LeftRight : Direction::TopBottom;
        $marks = \array_map(
            static fn (Mark $mark): Mark => MarkKind::Polyline === $mark->kind && $mark->dashed && $mark->points[0] === $mark->points[1] ? self::separator($mark, $stack, $width, $height) : $mark,
            $marks,
        );
        $title = $this->title($node);
        $description = $this->description($node);
        [$titleWidth, $titleHeight] = $title->size();
        [$descriptionWidth, $descriptionHeight] = $description->size();
        $extra = $descriptionHeight > 0 ? self::MARGIN : 0.0;
        $boxWidth = \max($titleWidth, $descriptionWidth, $width) + (4 * self::MARGIN);
        $boxHeight = $titleHeight + $descriptionHeight + $height + (4 * self::MARGIN) + $extra;
        $top = (2 * self::MARGIN) + $titleHeight + $descriptionHeight + $extra;
        $frame = $this->frame($node, 0.0, 0.0, $boxWidth, $boxHeight, $titleHeight, $title, $description);

        return [[...$frame, ...\array_map(static fn (Mark $mark): Mark => self::moved($mark, ($boxWidth - $width) / 2, $top + self::MARGIN), $marks)], $boxWidth, $boxHeight];
    }

    /** A region separator, drawn across the whole stack. */
    private static function separator(Mark $mark, Direction $stack, float $width, float $height): Mark
    {
        [$x, $y] = $mark->points[0];

        return new Mark(
            MarkKind::Polyline,
            Direction::LeftRight === $stack ? [[$x, 0.0], [$x, $height]] : [[0.0, $y], [$width, $y]],
            stroke: $mark->stroke,
            dashed: true,
            strokeWidth: $mark->strokeWidth,
            dashes: [5.0, 5.0],
        );
    }

    /**
     * A composite state's frame: the rounded box, its name over a line, and its description.
     *
     * @return list<Mark>
     */
    private function frame(StateNode $node, float $x, float $y, float $width, float $height, float $titleHeight, PlantUmlText $title, PlantUmlText $description): array
    {
        $style = $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'state'], $node->stereotypes);
        $line = ClassColors::line($node->color) ?? $style->color('LineColor', '#181818');
        $stroke = $style->number('LineThickness', 0.5);
        $rule = $y + (2 * self::MARGIN) + $titleHeight;

        $band = ClassColors::back($node->color) ?? $style->color('BackGroundColor', '#f1f1f1');
        $body = ClassColors::back($node->color) ?? $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'state', 'body'], $node->stereotypes)->color('BackGroundColor', 'none');
        $round = \min($style->number('RoundCorner', 25.0) / 2, $rule - $y);
        $outline = ClassBox::rectangle($x, $y, $width, $height, $line, $body, $stroke, $style->number('RoundCorner', 25.0));
        // The name's band takes the state's colour; the body below stays as its style says, clear by default.
        $top = \array_values(\array_filter($outline->points, static fn (array $point): bool => $point[1] <= ($y + $round + 1e-9)));
        $middle = $x + ($width / 2);
        $left = \array_values(\array_filter($top, static fn (array $point): bool => $point[0] < $middle));
        $right = \array_values(\array_filter($top, static fn (array $point): bool => $point[0] >= $middle));

        return [
            $outline,
            new Mark(MarkKind::Polygon, [[$x, $rule], ...$left, ...$right, [$x + $width, $rule]], stroke: 'none', fill: $band, strokeWidth: 0.0),
            new Mark(MarkKind::Polygon, $outline->points, stroke: $line, fill: 'none', strokeWidth: $stroke),
            new Mark(MarkKind::Polyline, [[$x, $rule], [$x + $width, $rule]], stroke: $line, strokeWidth: $stroke),
            ...$title->marks($x, $y + self::MARGIN, $width),
            ...$description->marks($x + self::MARGIN, $rule + self::MARGIN, $width, 'left'),
        ];
    }

    private function title(StateNode $node): PlantUmlText
    {
        return PlantUmlText::styled($node->display, $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'state', 'name'], $node->stereotypes));
    }

    private function description(StateNode $node): PlantUmlText
    {
        return PlantUmlText::styled($node->description, $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'state'], $node->stereotypes));
    }

    private function label(int $index): PlantUmlText
    {
        $link = $this->states->links[$index];
        $lines = null === $link->label ? [] : ClassReader::split($link->label);
        foreach ($this->states->notes as $note) {
            $lines = $note->link === $index ? [...$lines, ...$note->lines] : $lines;
        }

        return PlantUmlText::styled($lines, $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'arrow']));
    }

    /**
     * The notes of a scope as nodes of its graph, each beside its state.
     *
     * @param array<string, true> $placed
     * @return array<string, NoteBox>
     */
    private function notes(Svek $svek, array $placed, ?string $group, int $region): array
    {
        $style = $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'note']);
        $boxes = [];
        foreach ($this->states->notes as $note) {
            $mine = null === $note->entity ? 0 === $region && $note->group === $group && null === $note->link : \array_key_exists($note->entity, $placed);
            if (!$mine) {
                continue;
            }
            $box = new NoteBox(PlantUmlText::styled($note->lines, $style), $style, ClassColors::back($note->color));
            $boxes[$note->id] = $box;
            [$width, $height] = $box->size();
            $svek->node($note->id, $width, $height, null === $note->entity ? null : $this->clusterOf($note->entity, $group));
            if (null === $note->entity) {
                continue;
            }
            $across = \in_array($note->position, ['left', 'right'], strict: true) ? 1 : 2;
            \in_array($note->position, ['left', 'top'], strict: true) ? $svek->edge($note->id, $note->entity, $across) : $svek->edge($note->entity, $note->id, $across);
        }

        return $boxes;
    }

    /** The cluster of a scope's graph that holds a state, null at its top. */
    private function clusterOf(string $id, ?string $group): ?string
    {
        $owner = $this->states->nodes[$id]->group;

        return $owner === $group ? null : $owner;
    }

    /**
     * @param list<StateNode> $clusters
     * @param array<int, int> $edges
     * @param array<string, StateBox> $boxes
     * @param array<string, array{list<Mark>, float, float}> $inner
     * @param array<string, NoteBox> $notes
     * @return list<Mark>
     */
    private function paint(Placement $placement, array $clusters, array $edges, array $boxes, array $inner, array $notes): array
    {
        $marks = [];
        $boxesById = [];
        foreach ($placement->graph->clusters as $box) {
            $boxesById[$box->name] = $box;
        }
        foreach ($clusters as $node) {
            $box = $boxesById[$node->id];
            [, $titleHeight] = $this->title($node)->size();
            \array_push($marks, ...$this->frame($node, $box->x0, $box->y0, $box->x1 - $box->x0, $box->y1 - $box->y0, $titleHeight, $this->title($node), $this->description($node)));
        }
        $style = $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'arrow']);
        $background = $this->states->document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        $placement = self::framed($placement);
        foreach ($edges as $index => $edge) {
            \array_push($marks, ...LinkPainter::marks($this->states->links[$index], $placement, $edge, $this->label($index), $style, $background));
        }
        foreach ($this->states->notes as $note) {
            if (null === $note->entity || !\array_key_exists($note->id, $notes)) {
                continue;
            }
            $from = $placement->endpoints[$note->id];
            $to = $placement->endpoints[$note->entity];
            $marks[] = new Mark(MarkKind::Polyline, BoxClip::clipped([[$from->x, $from->y], [$to->x, $to->y]], $from, $to), stroke: '#181818', dashed: true, strokeWidth: 0.5, dashes: [7.0, 7.0]);
        }
        foreach ($boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            \array_push($marks, ...$box->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }
        foreach ($inner as $id => [$innerMarks]) {
            $node = $placement->endpoints[$id];
            foreach ($innerMarks as $mark) {
                $marks[] = self::moved($mark, $node->x - ($node->w / 2), $node->y - ($node->h / 2));
            }
        }
        foreach ($notes as $id => $note) {
            $node = $placement->endpoints[$id];
            \array_push($marks, ...$note->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }

        return $marks;
    }

    private static function anchor(string $composite): string
    {
        return '*anchor*'.$composite;
    }

    /**
     * The placement with each clustered composite state standing as its frame, so a
     * transition to it, routed to its anchor, is trimmed where it meets the frame.
     */
    private static function framed(Placement $placement): Placement
    {
        $endpoints = $placement->endpoints;
        $routes = $placement->routes;
        // Every cluster of a scope's graph is a composite state.
        foreach ($placement->graph->clusters as $box) {
            $endpoints[$box->name] = new GraphNode(0, $box->name, '', ($box->x0 + $box->x1) / 2, ($box->y0 + $box->y1) / 2, $box->x1 - $box->x0, $box->y1 - $box->y0, null, null, dashed: false);
        }

        return new Placement($placement->graph, $endpoints, $placement->directions, $routes, $placement->labels);
    }

    private static function moved(Mark $mark, float $x, float $y): Mark
    {
        return new Mark(
            $mark->kind,
            \array_map(static fn (array $point): array => [$point[0] + $x, $point[1] + $y], $mark->points),
            $mark->text,
            $mark->stroke,
            $mark->fill,
            $mark->dashed,
            $mark->strokeWidth,
            $mark->font,
            $mark->fontScale,
            $mark->fillOpacity,
            $mark->opacity,
            $mark->angle,
            $mark->gradient,
            $mark->dashes,
        );
    }
}
