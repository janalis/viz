<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Svek\Svek;

/**
 * A legacy activity diagram laid out as svek lays it out and drawn as PlantUML draws it:
 * every entity a box of its image's size, notes among them, partitions as clusters drawn as
 * packages, svek's separations for activity diagrams — 20 between nodes and 40 between ranks
 * at least. In points, set in the document's frame.
 */
final class LegacyActivityDrawing
{
    /** @var array<string, LegacyBox> */
    private array $boxes = [];
    /** @var array<int, int> */
    private array $edges = [];

    private function __construct(
        private readonly PlantUmlLegacyActivity $diagram,
    ) {}

    /** @return \Generator<int, null, mixed, Drawing> */
    public static function draw(PlantUmlLegacyActivity $diagram): \Generator
    {
        $drawing = new self($diagram);
        $placing = $drawing->graph()->place($diagram->direction, ...$drawing->separations());
        yield from $placing;
        $placement = $placing->getReturn();

        return PlantUmlFrame::svek($diagram->document, $drawing->paint($placement));
    }

    /** @return array{float, float} svek's least separations for activity diagrams, or `nodesep` and `ranksep` as written */
    private function separations(): array
    {
        $nodes = $this->diagram->document->param('nodesep');
        $ranks = $this->diagram->document->param('ranksep');

        return [\is_numeric($nodes) && (float) $nodes > 0 ? (float) $nodes : 20.0, \is_numeric($ranks) && (float) $ranks > 0 ? (float) $ranks : 40.0];
    }

    private function graph(): Svek
    {
        $svek = new Svek();
        foreach ($this->diagram->groups as $group) {
            $svek->cluster($group->id, $group->parent, ...Svek::label(...$this->title($group)->size()));
        }
        foreach ($this->diagram->entities as $entity) {
            $box = LegacyBox::of($entity, $this->diagram->document->styles, $this->diagram->direction);
            $this->boxes[$entity->id] = $box;
            $svek->node($entity->id, $box->width, $box->height, $entity->group);
        }
        foreach ($this->diagram->links as $index => $link) {
            [$width, $height] = $this->label($index)->size();
            $this->edges[$index] = $svek->edge(
                $link->from,
                $link->to,
                $link->length,
                $width > 0 ? $width + Svek::labelMargin($link->from, $link->to) : 0.0,
                $height > 0 ? $height + Svek::labelMargin($link->from, $link->to) : 0.0,
                !$link->norank,
                $link->reversed,
            );
        }

        return $svek;
    }

    private function title(LegacyGroup $group): PlantUmlText
    {
        $stereotypes = null === $group->stereotype ? [] : [\trim($group->stereotype, characters: '<> ')];

        return PlantUmlText::styled(ClassReader::split($group->display), $this->diagram->document->styles->style(['root', 'element', 'activityDiagram', 'package', 'title'], $stereotypes));
    }

    private function label(int $index): LinkLabel
    {
        $link = $this->diagram->links[$index];
        $styles = $this->diagram->document->styles;
        $notes = \array_values(\array_filter($this->diagram->notes, static fn (PlantUmlNote $note): bool => $note->link === $index));

        return LinkLabel::of(null === $link->label ? [] : ClassReader::split($link->label), $styles->style(['root', 'element', 'activityDiagram', 'arrow']), $notes, $styles->style([
            'root',
            'element',
            'activityDiagram',
            'note',
        ]));
    }

    /**
     * The notes svek draws pointing at what they are linked to, as its opale draws a note whose
     * single link dot routes in one curve — from a rank to the next,
     * or across a rank with nothing between — by their name: the index of that link, not drawn.
     *
     * @return array<string, int>
     */
    private function pointers(Placement $placement): array
    {
        $pointers = [];
        foreach ($this->diagram->entities as $entity) {
            if ('note' !== $entity->kind) {
                continue;
            }
            // A note's name cannot be written, so it has a single link: the one it was made with.
            $index = (int) \array_key_first(\array_filter($this->diagram->links, static fn (PlantUmlLink $link): bool => $link->from === $entity->id || $link->to === $entity->id));
            $link = $this->diagram->links[$index];
            if (!self::curve($placement, $link, $this->edges[$index])) {
                continue;
            }
            $pointers[$entity->id] = $index;
        }

        return $pointers;
    }

    /** Whether dot routes a link in a single curve: to the next rank, or across its own with no node between its ends. */
    private static function curve(Placement $placement, PlantUmlLink $link, int $edge): bool
    {
        if (\count($placement->routes[$edge] ?? []) > 2) {
            return false;
        }
        [$from, $to] = [$placement->endpoints[$link->from], $placement->endpoints[$link->to]];
        if (\abs($from->y - $to->y) > 0.001) {
            return true;
        }
        foreach ($placement->graph->nodes as $node) {
            if (\abs($node->y - $from->y) < 0.001 && $node->x > \min($from->x, $to->x) && $node->x < \max($from->x, $to->x)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<Mark> */
    private function paint(Placement $placement): array
    {
        $marks = [];
        $styles = $this->diagram->document->styles;
        foreach ($placement->graph->clusters as $box) {
            $group = $this->diagram->groups[$box->name];
            $style = $styles->style(['root', 'element', 'activityDiagram', 'package']);
            $line = $style->color('LineColor', '#000000');
            $back = ClassColors::back($group->color) ?? 'none';
            $title = $this->title($group);
            [$titleWidth, $titleHeight] = $title->size();
            // As PlantUML's folder: a tab as wide as the title and 6 more, slanting 7 across to its foot, as tall as the title and 6 more.
            [$x0, $y0, $x1, $y1, $tab, $foot] = [$box->x0, $box->y0, $box->x1, $box->y1, $box->x0 + $titleWidth + 6, $box->y0 + $titleHeight + 6];
            $thickness = $style->number('LineThickness', 1.5);
            $marks[] = new Mark(MarkKind::Polygon, [[$x0, $y0], [$tab, $y0], [$tab + 7, $foot], [$x1, $foot], [$x1, $y1], [$x0, $y1]], stroke: $line, fill: $back, strokeWidth: $thickness);
            $marks[] = new Mark(MarkKind::Polyline, [[$x0, $foot], [$tab + 7, $foot]], stroke: $line, strokeWidth: $thickness);
            \array_push($marks, ...$title->marks($box->x0 + 4.0, $box->y0 + 2.0, $title->size()[0], 'left'));
        }
        $style = $styles->style(['root', 'element', 'activityDiagram', 'arrow']);
        $background = $styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        $pointers = $this->pointers($placement);
        foreach ($this->edges as $index => $edge) {
            if (\in_array($index, $pointers, strict: true)) {
                continue;
            }
            \array_push($marks, ...LinkPainter::marks($this->diagram->links[$index], $placement, $edge, $this->label($index), $style, $background));
        }
        foreach ($this->boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            [$x, $y] = [$node->x - ($node->w / 2), $node->y - ($node->h / 2)];
            $index = $pointers[$id] ?? null;
            if (null === $index) {
                \array_push($marks, ...$box->marks($x, $y));
                continue;
            }
            $link = $this->diagram->links[$index];
            $other = $placement->endpoints[$link->from === $id ? $link->to : $link->from];
            $route = BoxClip::clipped(
                Route::smooth($placement->routes[$this->edges[$index]] ?? [[$node->x, $node->y], [$other->x, $other->y]]),
                $placement->endpoints[$link->from],
                $placement->endpoints[$link->to],
            );
            $route = $link->from === $id ? $route : \array_reverse($route);
            \array_push($marks, ...$box->pointing($x, $y, $route[0], $route[\count($route) - 1]));
        }

        return $marks;
    }
}
