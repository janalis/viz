<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassEntity;
use Janalis\Viz\PlantUML\Classes\ClassPortions;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\NoteLine;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\StackedText;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Svek\Frontier;
use Janalis\Viz\PlantUML\Svek\Squares;
use Janalis\Viz\PlantUML\Svek\Svek;

/**
 * A native description diagram laid out as svek lays it out and drawn as PlantUML draws it:
 * elements as boxes of their symbols' sizes, links a rank per dash past the first, notes
 * beside their elements or linked like elements, containers as clusters drawn with their own
 * symbols and titles. In points, set in the document's frame.
 */
final class DescriptionDrawing
{
    /** @var array<string, DescriptionBox> */
    private array $boxes = [];
    /** @var array<string, ClassBox> the `json` and `map` entities, drawn as a class diagram draws them */
    private array $tables = [];
    /** @var array<string, NoteBox> */
    private array $notes = [];
    /** @var array<int, int> */
    private array $edges = [];
    /** @var array<string, int> the edges of the notes beside containers, by note */
    private array $noteEdges = [];
    /** @var array<string, true> the containers a link starts or ends at, each with a point inside it */
    private array $anchors = [];
    /** @var array<string, true> the elements hidden, laid out but not drawn, as `hide` hides them */
    private array $hidden = [];
    /** @var list<Mark> what the hidden elements would draw, with their links and notes: measured, as PlantUML's limit finder takes them, not drawn */
    private array $ghosts = [];

    /** The prefix of the point svek puts in a container a link starts or ends at. */
    private const string ANCHOR = '*anchor*';

    private function __construct(
        private readonly PlantUmlDescriptions $diagram,
    ) {}

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlDescriptions $diagram): \Generator
    {
        $drawing = new self($diagram);
        $placing = $drawing->graph()->place($diagram->direction);
        yield from $placing;
        $placement = $placing->getReturn();
        // As PlantUML, which draws a lone element but a hexagon without dot, as its box 7 in from its sides: 1 less room past it, and measured as its box alone — lone among all it was given, those removed and their links included.
        $nodes = $placement->graph->nodes;
        $leaves = \count($diagram->elements) + \count(\array_filter($diagram->notes, static fn (PlantUmlNote $note): bool => null === $note->link));
        $alone = 1 === \count($nodes) && 1 === $leaves && [] === $diagram->links && [] === $placement->graph->clusters && 'hexagon' !== ($diagram->elements[$nodes[0]->name] ?? null)?->symbol;
        $box = $alone
            ? [new Mark(
                MarkKind::Polygon,
                ClassBox::corners($nodes[0]->x - ($nodes[0]->w / 2), $nodes[0]->y - ($nodes[0]->h / 2), $nodes[0]->w, $nodes[0]->h),
                stroke: 'none',
                fill: 'none',
                strokeWidth: 0.0,
            )] : null;

        $marks = $drawing->paint($placement);

        return PlantUmlFrame::svek(
            $diagram->document,
            $marks,
            trail: $alone ? 14.0 : 15.0,
            measured: $box ?? ([] === $drawing->ghosts ? null : [...$marks, ...$drawing->ghosts]),
            inFrame: $alone ? PlantUmlFrame::ALONE_SHORT : PlantUmlFrame::LAID_OUT,
        );
    }

    /** The room PlantUML's symbols keep around a container's title for their shape: a node's depth, a database's lid. */
    public const array SHAPE_ROOM = ['node' => [60.0, 5.0], 'database' => [0.0, 15.0]];

    /** How far below a container's top its title stands, where PlantUML sets it lower than in a plain box: 11 more, 20 under a database's lid, 5 in storage. */
    public const array TITLE_TOPS = ['node' => 13.0, 'cloud' => 13.0, 'component' => 13.0, 'stack' => 13.0, 'database' => 22.0, 'storage' => 7.0];

    private function graph(): Svek
    {
        $svek = new Svek();
        foreach ($this->diagram->groups as $group) {
            if ('together' === $group->symbol) {
                $svek->together($group->id, $group->parent);
                continue;
            }
            [$width, $height] = $this->title($group)->size();
            [$shapeWidth, $shapeHeight] = self::SHAPE_ROOM[$group->symbol] ?? [0.0, 0.0];
            $svek->cluster($group->id, $group->parent, ...Svek::label($width, $height, shapeWidth: $shapeWidth, shapeHeight: $shapeHeight));
        }
        $portions = new ClassPortions($this->diagram->visibility);
        $linked = [];
        foreach ($this->diagram->links as $link) {
            $linked[$link->from] = true;
            $linked[$link->to] = true;
        }
        // As PlantUML: an element removed taken out with its links and notes; one hidden laid out, drawn with nothing that touches it.
        $removed = [];
        foreach ($this->diagram->elements as $element) {
            if ($portions->hides($element->id, $element->stereotypes, \array_key_exists($element->id, $linked), 'removed')) {
                $removed[$element->id] = true;
                continue;
            }
            if ($portions->hides($element->id, $element->stereotypes, \array_key_exists($element->id, $linked))) {
                $this->hidden[$element->id] = true;
            }
        }
        $links = \array_filter($this->diagram->links, static fn (PlantUmlLink $link): bool => !\array_key_exists($link->from, $removed) && !\array_key_exists($link->to, $removed));
        $notes = \array_filter($this->diagram->notes, static fn (PlantUmlNote $note): bool => !\array_key_exists($note->entity ?? '', $removed));
        $beside = [];
        foreach ($links as $link) {
            if (1 !== $link->length) {
                continue;
            }
            $beside[$link->from] = true;
            $beside[$link->to] = true;
        }
        foreach ($this->diagram->elements as $element) {
            if (\array_key_exists($element->id, $removed)) {
                continue;
            }
            if (null !== $element->table) {
                $this->table($svek, $element, $element->table);
                continue;
            }
            $box = DescriptionBox::of($element, $this->diagram->document->styles);
            $box = \array_key_exists($element->id, $beside) ? $box->beside() : $box;
            $this->boxes[$element->id] = $box;
            \in_array($element->symbol, DescriptionBox::PORTS, strict: true)
                ? $svek->containerPort($element->id, (string) $element->group, 'portout' !== $element->symbol)
                : $svek->node($element->id, $box->width, $box->height, $element->group);
        }
        $style = $this->diagram->document->styles->style(['root', 'element', 'componentDiagram', 'note']);
        foreach ($notes as $note) {
            if (null !== $note->link) {
                continue;
            }
            $box = NoteBox::sectioned(PlantUmlText::styled($note->lines, $style), $style, ClassColors::back($note->color));
            $this->notes[$note->id] = $box;
            [$width, $height] = $box->size();
            $svek->node($note->id, $width, $height, $note->group);
        }
        foreach (Svek::linkOrder($links) as $index => $link) {
            [$width, $height] = $this->label($index)->size(Svek::labelMargin($link->from, $link->to));
            $this->edges[$index] = $svek->edge(
                $this->end($svek, $link->from),
                $this->end($svek, $link->to),
                $link->length,
                $width,
                $height,
                !$link->norank,
                $link->reversed,
            );
        }
        $groups = [];
        foreach ([...$this->diagram->elements, ...$notes] as $leaf) {
            $groups[$leaf->id] = $leaf->group;
        }
        $drawn = \array_values(\array_filter(
            $this->diagram->leaves,
            fn (string $id): bool => \array_key_exists($id, $this->boxes) || \array_key_exists($id, $this->tables) || \array_key_exists($id, $this->notes),
        ));
        Squares::put($svek, $drawn, $groups, [null, ...\array_keys($this->diagram->groups)], \array_values($links), \array_values($notes));
        foreach ($notes as $note) {
            if (null === $note->entity) {
                continue;
            }
            // As svek, a note beside a container stands beside a point inside its cluster.
            $end = $this->end($svek, $note->entity);
            $side = $note->side();
            $across = \in_array($side, ['left', 'right'], strict: true) ? 1 : 2;
            $this->noteEdges[$note->id] = \in_array($side, ['left', 'top'], strict: true) ? $svek->edge($note->id, $end, $across) : $svek->edge($end, $note->id, $across);
        }

        return $svek;
    }

    /** The node a link's end is laid out to: an element or a note, or, as svek lays out a link to a container, a point inside its cluster. */
    private function end(Svek $svek, string $id): string
    {
        if (!\array_key_exists($id, $this->diagram->groups)) {
            return $id;
        }
        if (!\array_key_exists($id, $this->anchors)) {
            $svek->point(self::ANCHOR.$id, $id);
            $this->anchors[$id] = true;
        }

        return self::ANCHOR.$id;
    }

    /** A group's title: its stereotypes in their style, as PlantUML draws them above its name. */
    private function title(DescriptionGroup $group): StackedText
    {
        $styles = $this->diagram->document->styles;
        $texts = [];
        if ([] !== $group->stereotypes && $group->stereotypeShown) {
            $style = $styles->style(['root', 'element', 'componentDiagram', $group->symbol, 'stereotype'], $group->stereotypes);
            $texts[] = PlantUmlText::styled(\array_map($styles->stereotype(...), $group->stereotypes), $style);
        }
        $texts[] = PlantUmlText::styled(
            ClassReader::split('' === $group->display ? $group->id : $group->display),
            $styles->style([
                'root',
                'element',
                'componentDiagram',
                'package',
                'title',
            ], $group->stereotypes),
        );

        return new StackedText($texts);
    }

    private function label(int $index): LinkLabel
    {
        $link = $this->diagram->links[$index];
        $styles = $this->diagram->document->styles;
        $notes = \array_values(\array_filter($this->diagram->notes, static fn (PlantUmlNote $note): bool => $note->link === $index));

        return LinkLabel::of(null === $link->label ? [] : ClassReader::split($link->label), $styles->style(['root', 'element', 'componentDiagram', 'arrow']), $notes, $styles->style([
            'root',
            'element',
            'componentDiagram',
            'note',
        ]));
    }

    /**
     * Each container's frame: its cluster's box; through its ports, as PlantUML's frontier
     * calculator finds it from what it holds.
     *
     * @return array<string, array{float, float, float, float}>
     */
    private function frames(Placement $placement): array
    {
        $frames = [];
        foreach ($placement->graph->clusters as $box) {
            $frames[$box->name] = [$box->x0, $box->y0, $box->x1, $box->y1];
        }
        foreach ($frames as $name => $frame) {
            [$insides, $points] = [[], []];
            foreach ($this->diagram->elements as $element) {
                if ($element->group !== $name || !\array_key_exists($element->id, $this->boxes)) {
                    continue;
                }
                $at = $placement->endpoints[$element->id];
                \in_array($element->symbol, DescriptionBox::PORTS, strict: true)
                    ? ($points[] = [$at->x, $at->y])
                    : ($insides[] = [$at->x - ($at->w / 2), $at->y - ($at->h / 2), $at->x + ($at->w / 2), $at->y + ($at->h / 2)]);
            }
            if ([] === $points) {
                continue;
            }
            foreach ($this->diagram->groups as $group) {
                \array_key_exists($group->id, $frames) && $group->parent === $name ? ($insides[] = $frames[$group->id]) : null;
            }
            $frames[$name] = Frontier::of($frame, $insides, $points, $this->diagram->direction, $this->title($this->diagram->groups[$name])->size()[0]);
        }

        return $frames;
    }

    /** @return list<Mark> */
    private function paint(Placement $placement): array
    {
        $marks = [];
        $frames = $this->frames($placement);
        foreach ($placement->graph->clusters as $box) {
            $group = $this->diagram->groups[$box->name];
            if ('together' === $group->symbol) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = $frames[$box->name];
            $style = $this->diagram->document->styles->style(['root', 'element', 'componentDiagram', $group->symbol], $group->stereotypes);
            $line = ClassColors::line($group->color) ?? $style->color('LineColor', '#181818');
            $back = ClassColors::back($group->color) ?? 'none';
            $title = $this->title($group);
            [$width, $height, $thickness] = [$x1 - $x0, $y1 - $y0, $style->number('LineThickness', 1.0)];
            \array_push(
                $marks,
                ...'frame' === $group->symbol
                    ? DescriptionShapes::frame($x0, $y0, $width, $height, $line, $back, $thickness, ...$title->size())
                    : DescriptionShapes::container(
                        $group->symbol,
                        $x0,
                        $y0,
                        $width,
                        $height,
                        $line,
                        $back,
                        $thickness,
                        $this->diagram->document->strictUml() ? 0.0 : $style->number('RoundCorner'),
                        $title->size(),
                    ),
            );
            // A package's, a folder's or a frame's title sits in its tab; any other container's is centred at its top, lower for the symbols PlantUML draws it lower in.
            $tabbed = \in_array($group->symbol, ['package', 'folder', 'frame'], strict: true);
            [$tabX, $tabY] = 'frame' === $group->symbol ? [3.0, 1.0] : [4.0, 2.0];
            \array_push($marks, ...$title->marks(
                $tabbed ? $x0 + $tabX : $x0 + ('node' === $group->symbol ? -4.0 : 0.0),
                $y0 + ($tabbed ? $tabY : self::TITLE_TOPS[$group->symbol] ?? 2.0),
                $tabbed ? $title->size()[0] : $x1 - $x0,
                $tabbed ? 'left' : 'center',
            ));
        }
        // As PlantUML's limit finder is seen to take the point a link to a container ends at: 14 either side of it.
        foreach (\array_keys($this->anchors) as $id) {
            $anchor = $placement->endpoints[self::ANCHOR.$id];
            $marks[] = new Mark(MarkKind::Polygon, [[$anchor->x - 14.0, $anchor->y], [$anchor->x + 14.0, $anchor->y]], stroke: 'none', fill: 'none', strokeWidth: 0.0, outline: Outline::Path);
        }
        // A link to a container is cut where it meets the container's outline.
        $endpoints = $placement->endpoints;
        foreach ($frames as $name => [$x0, $y0, $x1, $y1]) {
            $endpoints[$name] = new GraphNode(0, $name, '', ($x0 + $x1) / 2, ($y0 + $y1) / 2, $x1 - $x0, $y1 - $y0, null, null, dashed: false);
        }
        $placement = new Placement($placement->graph, $endpoints, $placement->directions, $placement->routes, $placement->labels, $placement->splines, controls: $placement->controls);
        $style = $this->diagram->document->styles->style(['root', 'element', 'componentDiagram', 'arrow']);
        $background = $this->diagram->document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        $tabs = \array_filter(\array_map(static fn (DescriptionBox $box): ?array => $box->magnet(), $this->boxes));
        // The notes svek draws pointing at the elements they stand beside, or are linked to; the link they point along not drawn.
        $pointers = NoteLine::pointers($placement, $this->diagram->notes, $this->notes, $this->noteEdges, $this->diagram->links, $this->edges, [...$this->boxes, ...$this->tables]);
        $drawn = \array_diff($this->edges, \array_map(static fn (array $pointer): int => $pointer[2], $pointers));
        // As dot sets end labels, each clear of the others'.
        $shown = \array_filter($drawn, fn (int $index): bool => !$this->diagram->links[$index]->hidden, \ARRAY_FILTER_USE_KEY);
        $ends = LinkPainter::endBoxes(
            $placement,
            \array_values(\array_map(fn (int $index): array => [$this->diagram->links[$index], $drawn[$index], $this->label($index), $style->fontSize(), [], [null, null]], \array_keys($shown))),
        );
        foreach ($drawn as $index => $edge) {
            $link = $this->diagram->links[$index];
            $this->put($marks, LinkPainter::marks($link, $placement, $edge, $this->label($index), $style, $background, tabs: $tabs, ends: $ends[$edge] ?? [null, null]), $link->from, $link->to);
        }
        // Any other note's line to what it stands beside, as dot draws its edge.
        foreach ($this->diagram->notes as $note) {
            if (null === $note->entity || !\array_key_exists($note->id, $this->notes) || \array_key_exists($note->id, $pointers)) {
                continue;
            }
            $this->put($marks, NoteLine::routed($note, $placement, $this->noteEdges[$note->id], $style, $this->diagram->document->styles->style([
                'root',
                'element',
                'componentDiagram',
                'note',
            ])), $note->entity);
        }
        foreach ($this->boxes as $id => $box) {
            $this->put($marks, $box->marks(...self::corner($placement, $id)), $id);
            $group = $this->diagram->elements[$id]->group;
            if (null !== $group && \in_array($this->diagram->elements[$id]->symbol, DescriptionBox::PORTS, strict: true)) {
                $this->put($marks, $box->portName(...[...self::corner($placement, $id), ($frames[$group][1] + $frames[$group][3]) / 2]), $id);
            }
        }
        foreach ($this->tables as $id => $table) {
            $this->put($marks, $table->marks(...self::corner($placement, $id)), $id);
        }
        $this->drawNotes($marks, $placement, $pointers);

        return $marks;
    }

    /**
     * The notes, each pointing at what it stands beside where svek draws it so.
     *
     * @param list<Mark> $marks
     * @param array<string, array{array{float, float}, array{float, float}, int}> $pointers
     */
    private function drawNotes(array &$marks, Placement $placement, array $pointers): void
    {
        $attached = \array_column(\array_map(static fn (PlantUmlNote $note): array => [$note->id, $note->entity ?? ''], $this->diagram->notes), 1, 0);
        foreach ($this->notes as $id => $note) {
            [$x, $y] = self::corner($placement, $id);
            $this->put($marks, \array_key_exists($id, $pointers) ? $note->pointing($x, $y, $pointers[$id][0], $pointers[$id][1]) : $note->marks($x, $y), $attached[$id] ?? '');
        }
    }

    /** A `json` or `map` entity, as svek writes one: a table, its rows each, or a `json` entity's one, its height in whole points down. */
    private function table(Svek $svek, DescriptionElement $element, ClassEntity $entity): void
    {
        $table = ClassBox::of($entity, $this->diagram->document->styles, new ClassPortions([]));
        $this->tables[$element->id] = $table;
        $svek->node($element->id, $table->width, $table->height, $element->group);
        $svek->rows($element->id, 'map' === $entity->type ? $table->rows() : \floor($table->height));
    }

    /** @return array{float, float} a node's top left corner */
    private static function corner(Placement $placement, string $id): array
    {
        $node = $placement->endpoints[$id];

        return [$node->x - ($node->w / 2), $node->y - ($node->h / 2)];
    }

    /**
     * Adds what is drawn to the marks, or, when it touches a hidden element, to what is only measured.
     *
     * @param list<Mark> $marks
     * @param list<Mark> $drawn
     * @param int|string ...$touching the elements it touches, by id, a numeric one as PHP keys it
     */
    private function put(array &$marks, array $drawn, int|string ...$touching): void
    {
        if (\array_any($touching, fn (int|string $id): bool => \array_key_exists($id, $this->hidden))) {
            \array_push($this->ghosts, ...$drawn);

            return;
        }
        \array_push($marks, ...$drawn);
    }
}
