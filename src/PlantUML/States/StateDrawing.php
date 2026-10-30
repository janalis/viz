<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Descriptions\DescriptionShapes;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\NoteLine;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Svek\Frontier;
use Janalis\Viz\PlantUML\Svek\Svek;
use Janalis\Viz\Source\Unsupported;
use Janalis\Viz\Text\FontFace;

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
    /** Dot's own node and rank separations, a quarter and half an inch. */
    private const float DOT_NODESEP = 18.0;
    private const float DOT_RANKSEP = 36.0;
    /** How far either side of a composite state's anchor PlantUML's limit finder is seen to reach. */
    private const float ANCHOR = 14.0;

    /** @var list<Mark> what the hidden states would draw, with their transitions and notes: measured, as PlantUML's limit finder takes them, not drawn */
    private array $ghosts = [];
    /** @var array<string, int> the edge of each note beside a state, in its scope's graph, by note */
    private array $noteEdges = [];

    private function __construct(
        private readonly PlantUmlStates $states,
    ) {}

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlStates $states): \Generator
    {
        $drawing = new self($states);
        $scope = $drawing->scope(null, 0);
        yield from $scope;
        $marks = $scope->getReturn();

        return PlantUmlFrame::svek($states->document, $marks, measured: [] === $drawing->ghosts ? null : [...$marks, ...$drawing->ghosts], inFrame: PlantUmlFrame::LAID_OUT_SHORT);
    }

    /**
     * A scope laid out and drawn: its marks, as dot placed them.
     *
     * @return \Generator<int, null, mixed, list<Mark>>
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
        /** @var array<string, ?string> $owners the cluster each node of the graph stands in */
        $owners = [];
        /** @var array<string, float> $shifts how far right of where its links end each point on a border stands */
        $shifts = [];
        $early = [];
        foreach ($this->members($group, $region) as [$node, $cluster]) {
            // As svek, a floating note made before a state comes before it.
            $early += $this->notes($svek, [], $group, $region, $node->id);
            $placed[$node->id] = true;
            $owners[$node->id] = $cluster;
            if (\array_key_exists($node->id, $this->states->composites) && !$this->autarkic($node->id)) {
                $svek->cluster($node->id, $cluster, ...Svek::label(...$this->title($node)->size(), ...$this->description($node)->size()));
                // As svek, each region no transition crosses into laid out on its own, an image in the cluster.
                foreach (StateRegions::images($this->states, $node->id) as $index) {
                    $image = $this->scope($node->id, $index);
                    yield from $image;
                    $id = $node->id.'#region'.$index;
                    $inner[$id] = PlantUmlFrame::svekImage($image->getReturn());
                    $svek->node($id, $inner[$id][1], $inner[$id][2], $node->id);
                }
                // As svek, a transition to or from a composite state drawn as a cluster ends at a point inside it.
                if ($this->endpoint($node->id)) {
                    $svek->point(self::anchor($node->id), $node->id);
                }
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
            $input = StateBox::BORDER[$node->kind] ?? null;
            if (null === $input) {
                $svek->node($node->id, $box->width, $box->height, $cluster);
                $box->table($svek);
                continue;
            }
            if (null === $cluster) {
                throw new Unsupported('The native PlantUML renderer draws points and pins only on a composite state a transition crosses into');
            }
            \in_array($node->kind, ['entry', 'exit'], strict: true)
                ? $svek->border($node->id, $cluster, $input, $box->nameWidth())
                : $svek->pin($node->id, $cluster, $input, $box->width, $box->height);
            $shifts[$node->id] = $svek->shift($node->id);
        }
        $notes = $early + $this->notes($svek, $placed, $group, $region, skip: $early);
        foreach ($this->states->notes as $note) {
            if (null === $note->entity || !\array_key_exists($note->id, $notes)) {
                continue;
            }
            $owners[$note->id] = $this->clusterOf($note->entity, $group);
        }
        $anchored = \array_map(static fn (StateNode $node): string => $node->id, $clusters);
        $edges = [];
        foreach (Svek::linkOrder($this->states->links) as $index => $link) {
            if (!\array_key_exists($link->from, $placed) || !\array_key_exists($link->to, $placed)) {
                continue;
            }
            [$width, $height] = $this->label($index)->size(Svek::labelMargin($link->from, $link->to));
            $ends = \array_map(static fn (string $id): string => \in_array($id, $anchored, strict: true) ? self::anchor($id) : $id, [$link->from, $link->to]);
            $edges[$index] = $svek->edge(
                $ends[0],
                $ends[1],
                $link->length,
                $width,
                $height,
                !$link->norank && !$this->pointToPoint($link->from, $link->to),
                $link->reversed,
            );
        }
        $this->noteEdges($svek, $notes);
        // As svek, a composite state's inner image is laid out with dot's own separations: its
        // graph is written without the diagram's nodesep and ranksep lines.
        $placing = null === $group ? $svek->place($this->states->direction) : $svek->place($this->states->direction, self::DOT_NODESEP, self::DOT_RANKSEP);
        yield from $placing;
        $placement = $placing->getReturn();

        return $this->paint($placement, $clusters, $edges, $boxes, $inner, $notes, $owners, $shifts);
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
            if ($node->group !== $group || $node->region !== $region || $node->removed) {
                continue;
            }
            $members[] = [$node, null];
            if (\array_key_exists($node->id, $this->states->composites) && !$this->autarkic($node->id)) {
                foreach (StateRegions::crossed($this->states, $node->id) as $index) {
                    foreach ($this->members($node->id, $index) as [$inner, $cluster]) {
                        $members[] = [$inner, $cluster ?? $node->id];
                    }
                }
            }
        }

        return $members;
    }

    /** Whether a transition starts or ends at a state itself. */
    private function endpoint(string $id): bool
    {
        foreach ($this->states->links as $link) {
            if ($link->from === $id || $link->to === $id) {
                return true;
            }
        }

        return false;
    }

    /** Whether a transition joins two points on one composite state's border, which svek lets rank its ends freely, as PlantUML. */
    private function pointToPoint(string $from, string $to): bool
    {
        [$from, $to] = [$this->states->nodes[$from], $this->states->nodes[$to]];

        return $from->group === $to->group && \array_key_exists($from->kind, StateBox::BORDER) && \array_key_exists($to->kind, StateBox::BORDER);
    }

    /** Whether no transition joins a state inside a composite state to one outside it, and no point stands on its border. */
    private function autarkic(string $composite): bool
    {
        // As svek, a frame is a cluster however its states are linked.
        if ('frame' === $this->states->nodes[$composite]->kind) {
            return false;
        }
        foreach ($this->states->links as $link) {
            if ($this->inside($link->from, $composite) !== $this->inside($link->to, $composite)) {
                return false;
            }
        }
        foreach ($this->states->nodes as $node) {
            if ($node->group === $composite && \array_key_exists($node->kind, StateBox::BORDER)) {
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
            // As svek, each region is an image of its own, sized by what it draws.
            $regions[] = PlantUmlFrame::svekImage($scope->getReturn());
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

        // As PlantUML's `InnerStateAutonom`, the regions MARGIN in from the left, however wide the name.
        return [[...$frame, ...\array_map(static fn (Mark $mark): Mark => self::moved($mark, self::MARGIN, $top + self::MARGIN), $marks)], $boxWidth, $boxHeight];
    }

    /** A region separator, as PlantUML's `ConcurrentStates` draws it: across the whole stack and a dash further, 1.5 thick, in dashes of 8 every 18. */
    private static function separator(Mark $mark, Direction $stack, float $width, float $height): Mark
    {
        [$x, $y] = $mark->points[0];

        return new Mark(
            MarkKind::Polyline,
            Direction::LeftRight === $stack ? [[$x, 0.0], [$x, $height + 8]] : [[0.0, $y], [$width + 8, $y]],
            stroke: $mark->stroke,
            dashed: true,
            strokeWidth: 1.5,
            dashes: [8.0, 10.0],
        );
    }

    /**
     * A composite state's frame: the rounded box, its name over a line, and its description.
     *
     * @param float $titleTop how far below its top its name stands
     * @param float $below how far below its name its line runs
     * @return list<Mark>
     */
    private function frame(
        StateNode $node,
        float $x,
        float $y,
        float $width,
        float $height,
        float $titleHeight,
        PlantUmlText $title,
        PlantUmlText $description,
        float $titleTop = self::MARGIN,
        float $below = self::MARGIN,
    ): array {
        $style = $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'state'], $node->stereotypes);
        $line = ClassColors::line($node->color) ?? $style->color('LineColor', '#181818');
        $stroke = $style->number('LineThickness', 0.5);
        if ('frame' === $node->kind) {
            // As PlantUML's `USymbolFrame` draws a frame group: its corners a state's, clear, its name in a tab 10 wider and 3 taller, 3 in and 1 down.
            [$titleWidth] = $title->size();

            return [
                ClassBox::rectangle($x, $y, $width, $height, $line, 'none', $stroke, $style->number('RoundCorner', 25.0)),
                DescriptionShapes::frame($x, $y, $width, $height, $line, 'none', $stroke, $titleWidth, $titleHeight)[1],
                ...$title->marks($x + 3, $y + 1, $titleWidth, 'left'),
            ];
        }
        $rule = $y + $titleTop + $titleHeight + $below;

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
            ...$title->marks($x, $y + $titleTop, $width),
            ...$description->marks($x + self::MARGIN, $rule + self::MARGIN, $width, 'left'),
        ];
    }

    /** A composite state's name, or a frame's, in bold. */
    private function title(StateNode $node): PlantUmlText
    {
        return PlantUmlText::styled(
            $node->display,
            $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'state', 'name'], $node->stereotypes),
            'frame' === $node->kind ? FontFace::Bold : null,
        );
    }

    private function description(StateNode $node): PlantUmlText
    {
        return PlantUmlText::styled($node->description, $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'state'], $node->stereotypes));
    }

    private function label(int $index): LinkLabel
    {
        $link = $this->states->links[$index];
        $styles = $this->states->document->styles;
        $notes = \array_values(\array_filter($this->states->notes, static fn (PlantUmlNote $note): bool => $note->link === $index));

        return LinkLabel::of(null === $link->label ? [] : ClassReader::split($link->label), $styles->style(['root', 'element', 'stateDiagram', 'arrow']), $notes, $styles->style([
            'root',
            'element',
            'stateDiagram',
            'note',
        ]));
    }

    /**
     * The notes of a scope as nodes of its graph, each beside its state: the floating ones made
     * just before a state, or all those not yet laid out.
     *
     * @param array<string, true> $placed
     * @param ?string $before the state the floating notes are made just before, null for all the notes
     * @param array<string, NoteBox> $skip the notes already laid out
     * @return array<string, NoteBox>
     */
    private function notes(Svek $svek, array $placed, ?string $group, int $region, ?string $before = null, array $skip = []): array
    {
        $style = $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'note']);
        $boxes = [];
        foreach ($this->states->notes as $note) {
            $mine = null === $note->entity ? 0 === $region && $note->group === $group && null === $note->link : \array_key_exists($note->entity, $placed);
            $now = null === $before ? !\array_key_exists($note->id, $skip) : null === $note->entity && ($this->states->before[$note->id] ?? null) === $before;
            if (!$mine || !$now) {
                continue;
            }
            $box = NoteBox::sectioned(PlantUmlText::styled($note->lines, $style), $style, ClassColors::back($note->color));
            $boxes[$note->id] = $box;
            [$width, $height] = $box->size();
            $svek->node($note->id, $width, $height, null === $note->entity ? null : $this->clusterOf($note->entity, $group));
        }

        return $boxes;
    }

    /**
     * Lays each note beside a state out an edge from it or to it, after the transitions, as svek
     * writes the links PlantUML made in that order.
     *
     * @param array<string, NoteBox> $notes the scope's notes
     */
    private function noteEdges(Svek $svek, array $notes): void
    {
        foreach ($this->states->notes as $note) {
            if (null === $note->entity || !\array_key_exists($note->id, $notes)) {
                continue;
            }
            $side = $note->side();
            $across = \in_array($side, ['left', 'right'], strict: true) ? 1 : 2;
            $this->noteEdges[$note->id] = \in_array($side, ['left', 'top'], strict: true) ? $svek->edge($note->id, $note->entity, $across) : $svek->edge($note->entity, $note->id, $across);
        }
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
     * @param array<string, ?string> $owners
     * @param array<string, float> $shifts
     * @return list<Mark>
     */
    private function paint(Placement $placement, array $clusters, array $edges, array $boxes, array $inner, array $notes, array $owners, array $shifts): array
    {
        $marks = [];
        $frames = [];
        foreach ($placement->graph->clusters as $box) {
            $frames[$box->name] = [$box->x0, $box->y0, $box->x1, $box->y1];
        }
        $bordered = [];
        foreach ($clusters as $node) {
            $points = \array_filter($owners, static fn (?string $owner, string $id): bool => $owner === $node->id && \array_key_exists($id, $shifts), \ARRAY_FILTER_USE_BOTH);
            if ([] !== $points) {
                $bordered[$node->id] = true;
                $frames[$node->id] = $this->frontier($node, $frames, $placement, $owners, $shifts);
            }
        }
        foreach ($clusters as $node) {
            [$x0, $y0, $x1, $y1] = $frames[$node->id];
            [, $titleHeight] = $this->title($node)->size();
            // As PlantUML draws a composite state laid out as a cluster: its name 4 below its top, its line 1 below the name; 5 below and none with points on its border.
            $border = \array_key_exists($node->id, $bordered);
            \array_push($marks, ...$this->frame(
                $node,
                $x0,
                $y0,
                $x1 - $x0,
                $y1 - $y0,
                $titleHeight,
                $this->title($node),
                $this->description($node),
                titleTop: $border ? self::MARGIN : 4.0,
                below: $border ? 0.0 : 1.0,
            ));
        }
        $style = $this->states->document->styles->style(['root', 'element', 'stateDiagram', 'arrow']);
        $background = $this->states->document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        // As PlantUML's limit finder is seen to take the point its links to a composite state end at, as measured on its output: 14 either side of it.
        foreach ($clusters as $node) {
            $anchor = $placement->endpoints[self::anchor($node->id)] ?? null;
            if (null !== $anchor) {
                $marks[] = new Mark(
                    MarkKind::Polygon,
                    [[$anchor->x - self::ANCHOR, $anchor->y], [$anchor->x + self::ANCHOR, $anchor->y]],
                    stroke: 'none',
                    fill: 'none',
                    strokeWidth: 0.0,
                    outline: Outline::Path,
                );
            }
        }
        $placement = self::framed($placement, $frames);
        // The notes svek draws pointing at the states they stand beside; any other's line as dot draws its edge.
        $pointers = NoteLine::pointers($placement, $this->states->notes, $notes, $this->noteEdges, $this->states->links, $edges, $boxes);
        foreach ($edges as $index => $edge) {
            $link = $this->states->links[$index];
            $this->put($marks, LinkPainter::marks($link, $placement, $edge, $this->label($index), $style, $background), $link->from, $link->to);
        }
        foreach ($this->states->notes as $note) {
            if (null === $note->entity || !\array_key_exists($note->id, $notes) || \array_key_exists($note->id, $pointers)) {
                continue;
            }
            $this->put($marks, NoteLine::routed($note, $placement, $this->noteEdges[$note->id], $style, $this->states->document->styles->style([
                'root',
                'element',
                'stateDiagram',
                'note',
            ])), $note->entity);
        }
        foreach ($boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            [$left, $top] = [$node->x - ($node->w / 2) + ($shifts[$id] ?? 0.0), $node->y - ($node->h / 2)];
            $this->put($marks, $box->marks($left, $top), $id);
            $owner = $owners[$id] ?? null;
            if (\array_key_exists($id, $shifts) && null !== $owner) {
                // As PlantUML sets a point's name: above it when it stands above its frame's middle.
                \array_push($marks, ...$box->name($left, $top, ($frames[$owner][1] + $frames[$owner][3]) / 2));
            }
        }
        foreach ($inner as $id => [$innerMarks]) {
            $node = $placement->endpoints[$id];
            foreach ($innerMarks as $mark) {
                $marks[] = self::moved($mark, $node->x - ($node->w / 2), $node->y - ($node->h / 2));
            }
        }
        $attached = \array_column(\array_map(static fn (PlantUmlNote $note): array => [$note->id, $note->entity ?? ''], $this->states->notes), 1, 0);
        foreach ($notes as $id => $note) {
            $node = $placement->endpoints[$id];
            [$x, $y] = [$node->x - ($node->w / 2), $node->y - ($node->h / 2)];
            $this->put($marks, \array_key_exists($id, $pointers) ? $note->pointing($x, $y, $pointers[$id][0], $pointers[$id][1]) : $note->marks($x, $y), $attached[$id] ?? '');
        }

        return $marks;
    }

    /**
     * Adds what is drawn to the marks, or, when it touches a hidden state, to what is only measured.
     *
     * @param list<Mark> $marks
     * @param list<Mark> $drawn
     * @param int|string ...$touching the states it touches, by id, a numeric one as PHP keys it
     */
    private function put(array &$marks, array $drawn, int|string ...$touching): void
    {
        if (\array_any($touching, fn (int|string $id): bool => true === ($this->states->nodes[$id] ?? null)?->hidden)) {
            \array_push($this->ghosts, ...$drawn);

            return;
        }
        \array_push($marks, ...$drawn);
    }

    /**
     * The frame of a composite state with points on its border, as PlantUML's frontier
     * calculator finds it from what the state holds, the points and its cluster's box.
     *
     * @param array<string, array{float, float, float, float}> $frames
     * @param array<string, ?string> $owners
     * @param array<string, float> $shifts
     * @return array{float, float, float, float}
     */
    private function frontier(StateNode $node, array $frames, Placement $placement, array $owners, array $shifts): array
    {
        $insides = [];
        $points = [];
        foreach (\array_keys($owners, $node->id, strict: true) as $id) {
            $at = $placement->endpoints[$id];
            if (\array_key_exists($id, $shifts)) {
                $points[] = [$at->x + $shifts[$id], $at->y];
                continue;
            }
            $insides[] = $frames[$id] ?? [$at->x - ($at->w / 2), $at->y - ($at->h / 2), $at->x + ($at->w / 2), $at->y + ($at->h / 2)];
        }
        [$titleWidth, $titleHeight] = Svek::label(...$this->title($node)->size(), ...$this->description($node)->size());

        return Frontier::of($frames[$node->id], $insides, $points, $this->states->direction, ($titleHeight + 5) > 0 ? $titleWidth : 0.0);
    }

    private static function anchor(string $composite): string
    {
        return '*anchor*'.$composite;
    }

    /**
     * The placement with each clustered composite state standing as its frame, so a
     * transition to it, routed to its anchor, is trimmed where it meets the frame.
     */
    /** @param array<string, array{float, float, float, float}> $frames each composite state's frame, by id */
    private static function framed(Placement $placement, array $frames): Placement
    {
        $endpoints = $placement->endpoints;
        $routes = $placement->routes;
        // Every cluster of a scope's graph is a composite state.
        foreach ($placement->graph->clusters as $box) {
            [$x0, $y0, $x1, $y1] = $frames[$box->name];
            $endpoints[$box->name] = new GraphNode(0, $box->name, '', ($x0 + $x1) / 2, ($y0 + $y1) / 2, $x1 - $x0, $y1 - $y0, null, null, dashed: false);
        }

        return new Placement($placement->graph, $endpoints, $placement->directions, $routes, $placement->labels, $placement->splines, controls: $placement->controls);
    }

    private static function moved(Mark $mark, float $x, float $y): Mark
    {
        return Units::moved($mark, $x, $y);
    }
}
