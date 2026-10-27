<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Descriptions\DescriptionBox;
use Janalis\Viz\PlantUML\Descriptions\DescriptionDrawing;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\NoteLine;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Svek\LineOfSegments;
use Janalis\Viz\PlantUML\Svek\Squares;
use Janalis\Viz\PlantUML\Svek\Svek;
use Janalis\Viz\Source\Unsupported;

/**
 * A native class diagram laid out as svek lays it out and drawn as PlantUML draws it:
 * classifiers and notes as boxes of their drawn size, links as edges a rank per dash past
 * the first, packages as clusters; then packages, links with their marks and labels,
 * classifiers and notes, in points, set in the document's frame.
 */
final class ClassDrawing
{
    /** The prefix of the point svek puts in a package a link starts or ends at. */
    private const string ANCHOR_PREFIX = '*anchor*';

    /** @var array<string, ClassBox|DescriptionBox> */
    private array $boxes = [];
    /** @var array<string, NoteBox> */
    private array $notes = [];
    /** @var array<int, int> each drawn link's edge, by link */
    private array $edges = [];
    /** @var list<Mark> */
    private array $marks = [];
    /** @var array<string, true> the packages holding nothing, laid out as nodes */
    private array $empty = [];
    /** @var array<string, true> the packages a link starts or ends at, each with a point inside it */
    private array $anchors = [];
    /** @var array<string, int> the edges of the notes beside classifiers and packages, by note */
    private array $noteEdges = [];
    /** @var array<string, true> the classifiers hidden, laid out but not drawn */
    private array $hidden = [];
    /** @var array<int, array<int, float>> how far across each link's qualifier at its first end, 0, and its second, 1, is pushed */
    private array $pushed = [];
    /** @var list<Mark> what the hidden classifiers would draw, with their links and notes: measured, as PlantUML's limit finder takes them, not drawn */
    private array $ghosts = [];

    private function __construct(
        private readonly PlantUmlClasses $classes,
    ) {}

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlClasses $classes): \Generator
    {
        $drawing = new self($classes);
        $svek = $drawing->graph();
        $placing = $svek->place($classes->direction, ...self::separations($classes));
        yield from $placing;
        $placement = $placing->getReturn();

        // As PlantUML is seen to leave a lone class, which it lays out without dot, 2 less room past it, and to measure it without its type parameters.
        // A classifier removed still counts, as PlantUML counts its leaves; a package, empty or not, makes it laid out.
        $alone = 1 === \count($placement->graph->nodes) && [] === $classes->groups && \count($classes->entities) <= 1;
        $marks = $drawing->paint($placement);
        if ($alone && [] !== $drawing->notes) {
            // As PlantUML draws a lone note: as its box 7 in from its sides.
            $node = $placement->graph->nodes[0];
            $box = new Mark(MarkKind::Polygon, ClassBox::corners($node->x - ($node->w / 2), $node->y - ($node->h / 2), $node->w, $node->h), stroke: 'none', fill: 'none', strokeWidth: 0.0);

            return PlantUmlFrame::svek($classes->document, $marks, trail: 14.0, measured: [$box], inFrame: PlantUmlFrame::ALONE);
        }

        return PlantUmlFrame::svek(
            $classes->document,
            $marks,
            trail: $alone ? 13.0 : 15.0,
            measured: $alone ? $drawing->bare($placement) : $drawing->measured($marks),
            inFrame: $alone ? PlantUmlFrame::ALONE : PlantUmlFrame::LAID_OUT,
        );
    }

    /** @return array{float, float} svek's separations, or those `skinparam nodesep` and `ranksep` set */
    private static function separations(PlantUmlClasses $classes): array
    {
        $nodes = $classes->document->param('nodesep');
        $ranks = $classes->document->param('ranksep');

        return [\is_numeric($nodes) && (float) $nodes > 0 ? (float) $nodes : 35.0, \is_numeric($ranks) && (float) $ranks > 0 ? (float) $ranks : 60.0];
    }

    private function graph(): Svek
    {
        $svek = new Svek();
        $portions = new ClassPortions($this->classes->visibility);
        $icons = $this->classes->document->param('classattributeiconsize', '10');
        $held = [];
        foreach ([...$this->classes->entities, ...$this->classes->notes] as $member) {
            $held[$member->group ?? ''] = true;
        }
        foreach ($this->classes->groups as $group) {
            $held[$group->parent ?? ''] = true;
        }
        foreach ($this->classes->groups as $group) {
            $title = new PlantUmlText(ClassReader::split('' === $group->display ? $group->id : $group->display), 14.0);
            if ('together' === $group->type) {
                $svek->together($group->id, $group->parent);
                continue;
            }
            if (!\array_key_exists($group->id, $held)) {
                if (null !== $group->symbol) {
                    throw new Unsupported('The native PlantUML renderer does not draw an empty '.$group->symbol.' such as '.$group->id);
                }
                // As PlantUML's `EntityImageEmptyPackage`, a package holding nothing is a node: its title, as tall as two of its lines at least, 10 inside its sides.
                [$width, $height] = PlantUmlText::styled(
                    ClassReader::split('' === $group->display ? $group->id : $group->display),
                    $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'package', 'title']),
                )->size();
                $this->empty[$group->id] = true;
                $svek->node($group->id, $width + 20, (2 * $height) + 20, $group->parent);
                continue;
            }
            [$shapeWidth, $shapeHeight] = DescriptionDrawing::SHAPE_ROOM[ClassPackage::symbol($group)] ?? [0.0, 0.0];
            $svek->cluster($group->id, $group->parent, ...Svek::label(...$title->size(), shapeWidth: $shapeWidth, shapeHeight: $shapeHeight));
        }
        $linked = [];
        foreach ($this->classes->links as $link) {
            $linked[$link->from] = true;
            $linked[$link->to] = true;
        }
        // As PlantUML, the circled character takes its own size, else the document's default, else — or for a size not in whole points — 17.
        $document = $this->classes->document;
        $letter = $document->param('circledCharacterFontSize', $document->param('defaultFontSize'));
        $letter = 1 === \preg_match('/^\d+$/', $letter) && (int) $letter > 0 ? (int) $letter : 17;
        foreach ($this->classes->entities as $entity) {
            if ($portions->hidden($entity, \array_key_exists($entity->id, $linked), 'removed')) {
                continue;
            }
            if ($portions->hidden($entity, \array_key_exists($entity->id, $linked))) {
                // As PlantUML, a hidden classifier keeps its place, drawn with nothing that touches it.
                $this->hidden[$entity->id] = true;
            }
            // As PlantUML, an element mixed in drawn as a description diagram draws it, in the class diagram's styles.
            $box = null === $entity->element
                ? ClassBox::of($entity, $this->classes->document->styles, $portions, \is_numeric($icons) ? (float) $icons : 10.0, $letter)
                : DescriptionBox::of($entity->element, $this->classes->document->styles, diagram: 'classDiagram');
            $this->boxes[$entity->id] = $box;
            $svek->node($entity->id, $box->width, $box->height, $entity->group);
            if ($box instanceof ClassBox && 'map' === $entity->type) {
                $svek->rows($entity->id, $box->rows());
            }
        }
        $noteStyle = $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'note']);
        foreach ($this->classes->notes as $note) {
            $box = NoteBox::sectioned(PlantUmlText::styled($note->lines, $noteStyle), $noteStyle, ClassColors::back($note->color));
            if (!$this->placed($note)) {
                continue;
            }
            $this->notes[$note->id] = $box;
            [$width, $height] = $box->size();
            $svek->node($note->id, $width, $height, $note->group);
        }
        foreach ($this->classes->links as $index => $link) {
            [$from, $to] = [$this->end($svek, $link->from, $held), $this->end($svek, $link->to, $held)];
            if (null === $from || null === $to) {
                continue;
            }
            [$width, $height] = $this->label($index)->size(Svek::labelMargin($link->from, $link->to));
            $this->qualify($svek, $link);
            // Svek keeps a margin around a label, so it stands clear of the line.
            $this->edges[$index] = $svek->edge(
                $from,
                $to,
                $link->length,
                $width,
                $height,
                !$link->norank,
                $link->reversed,
            );
        }
        $groups = [];
        foreach ([...$this->classes->entities, ...$this->classes->notes] as $leaf) {
            $groups[$leaf->id] = $leaf->group;
        }
        $drawn = \array_values(\array_filter($this->classes->leaves, fn (string $id): bool => \array_key_exists($id, $this->boxes) || \array_key_exists($id, $this->notes)));
        Squares::put($svek, $drawn, $groups, [null, ...\array_keys($this->classes->groups)], $this->classes->links, $this->classes->notes);
        $this->noteEdges($svek, $held);

        return $svek;
    }

    /**
     * Lays each note beside a classifier or a package out an edge from it or to it: as svek, a
     * note beside a package stands beside a point inside its cluster.
     *
     * @param array<string, true> $held the groups holding something
     */
    private function noteEdges(Svek $svek, array $held): void
    {
        foreach ($this->classes->notes as $note) {
            $end = null === $note->entity || !\array_key_exists($note->id, $this->notes) ? null : $this->end($svek, $note->entity, $held);
            if (null === $end) {
                continue;
            }
            $across = \in_array($note->position, ['left', 'right'], strict: true) ? 1 : 2;
            $this->noteEdges[$note->id] = \in_array($note->position, ['left', 'top'], strict: true) ? $svek->edge($note->id, $end, $across) : $svek->edge($end, $note->id, $across);
        }
    }

    /**
     * The node a link's end is laid out to: a classifier drawn, a package holding nothing, or,
     * as svek lays out a link to a package, a point inside its cluster; null for one not drawn.
     *
     * @param array<string, true> $held the groups holding something
     */
    private function end(Svek $svek, string $id, array $held): ?string
    {
        if (\array_key_exists($id, $this->boxes) || \array_key_exists($id, $this->empty) || \array_key_exists($id, $this->notes)) {
            return $id;
        }
        if (!\array_key_exists($id, $held) || !\array_key_exists($id, $this->classes->groups)) {
            return null;
        }
        if (!\array_key_exists($id, $this->anchors)) {
            $svek->point(self::ANCHOR_PREFIX.$id, $id);
            $this->anchors[$id] = true;
        }

        return self::ANCHOR_PREFIX.$id;
    }

    /** A link's middle label: its text, and any note on the link below it. */
    private function label(int $index): LinkLabel
    {
        $link = $this->classes->links[$index];
        $styles = $this->classes->document->styles;
        $notes = \array_values(\array_filter($this->classes->notes, static fn (PlantUmlNote $note): bool => $note->link === $index));

        return LinkLabel::of(
            null === $link->label ? [] : ClassReader::split($link->label),
            $styles->style(['root', 'element', 'classDiagram', 'arrow']),
            $notes,
            $styles->style([
                'root',
                'element',
                'classDiagram',
                'note',
            ]),
            lead: null === $link->labelArrow ? 0.0 : LinkLabel::POINTED,
        );
    }

    /** @return list<Mark> */
    private function paint(Placement $placement): array
    {
        // As PlantUML's limit finder is seen to take the point a link to a package ends at: 14 either side of it.
        foreach (\array_keys($this->anchors) as $id) {
            $anchor = $placement->endpoints[self::ANCHOR_PREFIX.$id];
            $this->ghosts[] = new Mark(MarkKind::Polygon, [[$anchor->x - 14.0, $anchor->y], [$anchor->x + 14.0, $anchor->y]], stroke: 'none', fill: 'none', strokeWidth: 0.0, outline: Outline::Path);
        }
        // A link to a package is cut where it meets the package's frame.
        $endpoints = $placement->endpoints;
        foreach ($placement->graph->clusters as $box) {
            $endpoints[$box->name] = new GraphNode(0, $box->name, '', ($box->x0 + $box->x1) / 2, ($box->y0 + $box->y1) / 2, $box->x1 - $box->x0, $box->y1 - $box->y0, null, null, dashed: false);
        }
        $placement = new Placement($placement->graph, $endpoints, $placement->directions, $placement->routes, $placement->labels, $placement->splines, controls: $placement->controls);
        foreach ($placement->graph->clusters as $cluster) {
            $group = $this->classes->groups[$cluster->name] ?? null;
            if (null !== $group && 'together' !== $group->type) {
                \array_push($this->marks, ...ClassPackage::marks($group, $this->classes->document->styles, $cluster->x0, $cluster->y0, $cluster->x1, $cluster->y1));
            }
        }
        foreach (\array_keys($this->empty) as $id) {
            $node = $placement->endpoints[$id];
            \array_push($this->marks, ...ClassPackage::marks(
                $this->classes->groups[$id],
                $this->classes->document->styles,
                $node->x - ($node->w / 2),
                $node->y - ($node->h / 2),
                $node->x + ($node->w / 2),
                $node->y + ($node->h / 2),
                [
                    'root',
                    'element',
                    'classDiagram',
                    'package',
                    'title',
                ],
            ));
        }
        $this->push($placement);
        foreach ($this->edges as $index => $edge) {
            $link = $this->classes->links[$index];
            $drawn = $this->marks;
            // A hidden link, as PlantUML draws it unseen: measured as drawn.
            $seen = clone $link;
            $seen->hidden = false;
            $this->link($seen, $placement, $edge, $index);
            if ($link->hidden || \array_key_exists($link->from, $this->hidden) || \array_key_exists($link->to, $this->hidden)) {
                \array_push($this->ghosts, ...\array_slice($this->marks, \count($drawn)));
                $this->marks = $drawn;
            }
        }
        foreach ($this->classes->notes as $note) {
            $this->noteLink($note, $placement);
        }
        foreach ($this->boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            $marks = $box->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2));
            \array_key_exists($id, $this->hidden) ? \array_push($this->ghosts, ...$marks) : \array_push($this->marks, ...$marks);
        }
        $attached = \array_column(\array_map(static fn (PlantUmlNote $note): array => [$note->id, $note->entity ?? ''], $this->classes->notes), 1, 0);
        foreach ($this->notes as $id => $note) {
            $node = $placement->endpoints[$id];
            $marks = $note->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2));
            \array_key_exists($attached[$id] ?? '', $this->hidden) ? \array_push($this->ghosts, ...$marks) : \array_push($this->marks, ...$marks);
        }

        return $this->marks;
    }

    /** Whether a note is laid out as a node: not one on a link, which stands in its label, nor one on a classifier removed, which goes with it, as PlantUML has them. */
    private function placed(PlantUmlNote $note): bool
    {
        return null === $note->link && (null === $note->entity || \array_key_exists($note->entity, $this->boxes) || \array_key_exists($note->entity, $this->classes->groups));
    }

    /**
     * What PlantUML's limit finder takes of the diagram: all it draws, and what its hidden classifiers would.
     *
     * @param list<Mark> $marks
     * @return ?list<Mark>
     */
    private function measured(array $marks): ?array
    {
        return [] === $this->ghosts ? null : [...$marks, ...$this->ghosts];
    }

    /**
     * The classes as drawn, without their type parameters.
     *
     * @return list<Mark>
     */
    private function bare(Placement $placement): array
    {
        $marks = [];
        foreach ($this->boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            \array_push($marks, ...$box instanceof ClassBox ? $box->bare($node->x - ($node->w / 2), $node->y - ($node->h / 2)) : $box->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }

        // Alone, laid out without dot, a box is taken at its size, as PlantUML takes its image's.
        return \array_map(static fn (Mark $mark): Mark => Outline::Box === $mark->outline && MarkKind::Polygon === $mark->kind ? $mark->with(['outline' => Outline::Block]) : $mark, $marks);
    }

    private function link(PlantUmlLink $link, Placement $placement, int $edge, int $index): void
    {
        $style = $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'arrow']);
        $background = $this->classes->document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        $qualifiers = [$this->qualifier($link->from, $link->fromQualifier), $this->qualifier($link->to, $link->toQualifier)];
        foreach ([0, 1] as $end) {
            if (null === $qualifiers[$end]) {
                continue;
            }
            $qualifiers[$end][2] = $this->pushed[$index][$end] ?? 0.0;
        }
        \array_push($this->marks, ...LinkPainter::marks($link, $this->ported($link, $placement, $edge), $edge, $this->label($index), $style, $background, $this->shapes($link), $qualifiers));
    }

    /**
     * The layout a link leaving a map's row is drawn from, as dot draws one from a port: its
     * line from the middle of its row, cut where it leaves the row, across to its rank or down
     * from the row that ends svek's table; dot's curve out of the row's side otherwise left to
     * PlantUML.
     */
    private function ported(PlantUmlLink $link, Placement $placement, int $edge): Placement
    {
        $box = $this->boxes[$link->from] ?? null;
        $row = null === $link->fromPort || !$box instanceof ClassBox ? null : $box->port($link->fromPort);
        if (null === $row) {
            return $placement;
        }
        $node = $placement->endpoints[$link->from];
        $to = $placement->endpoints[$link->to];
        if (\abs($to->y - $node->y) > 1.0 && !($row[2] && $to->y > $node->y)) {
            throw new Unsupported('The native PlantUML renderer does not draw a link from a map\'s row out of its side to an element above or below it: '.$link->from);
        }
        $cell = new GraphNode(0, $link->from, '', $node->x, $node->y - ($node->h / 2) + $row[0] + ($row[1] / 2), $node->w, $row[1], null, null, dashed: false);
        $route = $placement->routes[$edge] ?? [[$node->x, $node->y], [$placement->endpoints[$link->to]->x, $placement->endpoints[$link->to]->y]];
        $route[0] = [$cell->x, $cell->y];
        [$routes, $splines, $controls] = [$placement->routes, $placement->splines, $placement->controls];
        $routes[$edge] = $route;
        unset($splines[$edge], $controls[$edge]);

        return new Placement($placement->graph, [$link->from => $cell] + $placement->endpoints, $placement->directions, $routes, $placement->labels, $splines, controls: $controls);
    }

    /**
     * @return array<string, string> the outline each end's line stops at: a circle's or a
     *     diamond's, as PlantUML draws them, else a box
     */
    private function shapes(PlantUmlLink $link): array
    {
        $shapes = [];
        foreach ([$link->from, $link->to] as $id) {
            $shapes[$id] = match (\array_key_exists($id, $this->classes->entities) ? $this->classes->entities[$id]->type : 'class') {
                'circle' => 'circle:'.ClassBox::DOT,
                'point' => 'circle:2',
                'lollipop' => 'circle:5',
                'diamond' => 'diamond',
                default => 'box',
            };
        }

        return $shapes;
    }

    /**
     * How far across each qualifier below or above a node is pushed, as PlantUML's `SvekNode`
     * pushes those on one side apart: each 5 wider either side than its box, in the order
     * their links were written.
     */
    private function push(Placement $placement): void
    {
        $sides = [];
        foreach ($this->edges as $index => $edge) {
            $link = $this->classes->links[$index];
            if (1 === $link->length) {
                continue;
            }
            $ends = LinkPainter::ends($link, $placement, $edge, $this->shapes($link));
            foreach ([[$link->from, $link->fromQualifier, 0, 'down'], [$link->to, $link->toQualifier, 1, 'up']] as [$id, $text, $end, $side]) {
                $qualifier = $this->qualifier($id, $text);
                if (null === $qualifier) {
                    continue;
                }
                $width = $qualifier[0]->size()[0] + 4;
                $start = $ends[$end][0] - ($width / 2) - 5;
                $sides[$id."\0".$side][] = [$index, $end, $start, $start + $width + 10];
            }
        }
        foreach ($sides as $segments) {
            $solved = LineOfSegments::solved(\array_map(static fn (array $segment): array => [$segment[2], $segment[3]], $segments));
            foreach ($segments as $at => [$index, $end, $start]) {
                $this->pushed[$index][$end] = $solved[$at] - $start;
            }
        }
    }

    /**
     * The room svek keeps beside a link's qualified ends, as PlantUML's `Kal` asks it: the
     * qualifier's width on the side a link laid across leaves by, else its height below its
     * first end, above its second.
     */
    private function qualify(Svek $svek, PlantUmlLink $link): void
    {
        foreach ([[$link->from, $link->fromQualifier, true], [$link->to, $link->toQualifier, false]] as [$id, $text, $first]) {
            $qualifier = $this->qualifier($id, $text);
            if (null === $qualifier) {
                continue;
            }
            [$width, $height] = $qualifier[0]->size();
            [$width, $height] = [$width + 4, $height + 2];
            $across = 1 === $link->length;
            $svek->margins($id, $across || $first ? 0.0 : $height, $across && $first ? $width : 0.0, !$across && $first ? $height : 0.0, $across && !$first ? $width : 0.0);
        }
    }

    /** @return ?array{PlantUmlText, Style, float} a qualifier's text and the style its box is drawn in, as PlantUML's `Kal` styles it, not yet pushed across */
    private function qualifier(string $id, ?string $text): ?array
    {
        if (null === $text) {
            return null;
        }
        $entity = $this->classes->entities[$id] ?? null;
        $style = $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'class', 'qualified'], null === $entity ? [] : $entity->stereotypes);

        return [PlantUmlText::styled(ClassReader::split($text), $style), $style, 0.0];
    }

    /** The dashed line from a note to the entity it stands beside. */
    private function noteLink(PlantUmlNote $note, Placement $placement): void
    {
        $entity = (string) $note->entity;
        // As PlantUML, a note's line to a package is its edge as dot draws it; to a classifier shown, a straight line.
        $routed = \array_key_exists($note->id, $this->noteEdges) && \array_key_exists($entity, $this->classes->groups);
        if (!$routed && (!\array_key_exists($note->id, $this->notes) || !\array_key_exists($entity, $this->boxes) || \array_key_exists($entity, $this->hidden))) {
            return;
        }
        $styles = $this->classes->document->styles;
        \array_push(
            $this->marks,
            ...$routed
                ? NoteLine::routed(
                    $note,
                    $placement,
                    $this->noteEdges[$note->id],
                    $styles->style(['root', 'element', 'classDiagram', 'arrow']),
                    $styles->style(['root', 'element', 'classDiagram', 'note']),
                )
                : [NoteLine::straight($placement, $note->id, $entity)],
        );
    }
}
