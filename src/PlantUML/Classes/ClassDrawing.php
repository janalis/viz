<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Svek\Svek;

/**
 * A native class diagram laid out as svek lays it out and drawn as PlantUML draws it:
 * classifiers and notes as boxes of their drawn size, links as edges a rank per dash past
 * the first, packages as clusters; then packages, links with their marks and labels,
 * classifiers and notes, in points, set in the document's frame.
 */
final class ClassDrawing
{
    /** @var array<string, ClassBox> */
    private array $boxes = [];
    /** @var array<string, NoteBox> */
    private array $notes = [];
    /** @var array<int, int> each drawn link's edge, by link */
    private array $edges = [];
    /** @var list<Mark> */
    private array $marks = [];

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

        // As PlantUML is seen to leave a class diagram 1 more room past it.
        return PlantUmlFrame::svek($classes->document, $drawing->paint($placement), trail: 16.0);
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
        foreach ($this->classes->groups as $group) {
            $title = new PlantUmlText(ClassReader::split('' === $group->display ? $group->id : $group->display), 14.0);
            if ('together' === $group->type) {
                $svek->together($group->id, $group->parent);
                continue;
            }
            $svek->cluster($group->id, $group->parent, ...Svek::label(...$title->size()));
        }
        $linked = [];
        foreach ($this->classes->links as $link) {
            $linked[$link->from] = true;
            $linked[$link->to] = true;
        }
        // As PlantUML, the circled character takes its own size, else the document's default, else 17.
        $document = $this->classes->document;
        $letter = $document->param('circledCharacterFontSize', $document->param('defaultFontSize'));
        $letter = \is_numeric($letter) && (float) $letter > 0 ? (float) $letter : 17.0;
        foreach ($this->classes->entities as $entity) {
            if ($portions->hidden($entity, \array_key_exists($entity->id, $linked))) {
                continue;
            }
            $box = ClassBox::of($entity, $this->classes->document->styles, $portions, \is_numeric($icons) ? (float) $icons : 10.0, $letter);
            $this->boxes[$entity->id] = $box;
            $svek->node($entity->id, $box->width, $box->height, $entity->group);
        }
        $noteStyle = $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'note']);
        foreach ($this->classes->notes as $note) {
            $box = new NoteBox(PlantUmlText::styled($note->lines, $noteStyle), $noteStyle, ClassColors::back($note->color));
            if (null !== $note->link) {
                continue;
            }
            $this->notes[$note->id] = $box;
            [$width, $height] = $box->size();
            $svek->node($note->id, $width, $height, $note->group);
        }
        foreach ($this->classes->links as $index => $link) {
            if (!\array_key_exists($link->from, $this->boxes) || !\array_key_exists($link->to, $this->boxes)) {
                continue;
            }
            [$width, $height] = $this->label($index)->size();
            // Svek keeps a margin around a label, so it stands clear of the line.
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
        foreach ($this->classes->notes as $note) {
            if (null === $note->entity || !\array_key_exists($note->entity, $this->boxes)) {
                continue;
            }
            $across = \in_array($note->position, ['left', 'right'], strict: true) ? 1 : 2;
            \in_array($note->position, ['left', 'top'], strict: true) ? $svek->edge($note->id, $note->entity, $across) : $svek->edge($note->entity, $note->id, $across);
        }

        return $svek;
    }

    /** A link's middle label: its text, and any note on the link below it. */
    private function label(int $index): LinkLabel
    {
        $link = $this->classes->links[$index];
        $styles = $this->classes->document->styles;
        $notes = \array_values(\array_filter($this->classes->notes, static fn (PlantUmlNote $note): bool => $note->link === $index));

        return LinkLabel::of(null === $link->label ? [] : ClassReader::split($link->label), $styles->style(['root', 'element', 'classDiagram', 'arrow']), $notes, $styles->style([
            'root',
            'element',
            'classDiagram',
            'note',
        ]));
    }

    /** @return list<Mark> */
    private function paint(Placement $placement): array
    {
        foreach ($placement->graph->clusters as $cluster) {
            $group = $this->classes->groups[$cluster->name] ?? null;
            if (null !== $group && 'together' !== $group->type) {
                $this->package($group, $cluster->x0, $cluster->y0, $cluster->x1, $cluster->y1);
            }
        }
        foreach ($this->edges as $index => $edge) {
            $this->link($this->classes->links[$index], $placement, $edge, $index);
        }
        foreach ($this->classes->notes as $note) {
            $this->noteLink($note, $placement);
        }
        foreach ($this->boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            \array_push($this->marks, ...$box->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }
        foreach ($this->notes as $id => $note) {
            $node = $placement->endpoints[$id];
            \array_push($this->marks, ...$note->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }

        return $this->marks;
    }

    /** A package: its frame with the tab its title sits in. */
    private function package(ClassGroup $group, float $x0, float $y0, float $x1, float $y1): void
    {
        $style = $this->classes->document->styles->style(['root', 'element', 'group', 'package'], null === $group->stereotype ? [] : ClassReader::stereotypes($group->stereotype));
        $title = PlantUmlText::styled(ClassReader::split('' === $group->display ? $group->id : $group->display), $this->classes->document->styles->style(['root', 'element', 'package', 'title']));
        [$width, $height] = $title->size();
        $tab = $height + 6;
        $line = $style->color('LineColor', '#000000');
        $stroke = $style->number('LineThickness', 1.5);
        $back = ClassColors::back($group->color) ?? $style->color('BackGroundColor', 'none');
        $this->marks[] = new Mark(
            MarkKind::Polygon,
            [[$x0, $y0], [$x0 + $width + 12, $y0], [$x0 + $width + 12, $y0 + $tab], [$x1, $y0 + $tab], [$x1, $y1], [$x0, $y1]],
            stroke: $line,
            fill: $back,
            strokeWidth: $stroke,
            // As PlantUML, which draws it as a path, its limit finder taking it as it runs.
            outline: Outline::Path,
        );
        $this->marks[] = new Mark(MarkKind::Polyline, [[$x0, $y0 + $tab], [$x0 + $width + 12, $y0 + $tab]], stroke: $line, strokeWidth: $stroke);
        \array_push($this->marks, ...$title->marks($x0 + 4, $y0 + 2, $width));
    }

    private function link(PlantUmlLink $link, Placement $placement, int $edge, int $index): void
    {
        $style = $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'arrow']);
        $background = $this->classes->document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        \array_push($this->marks, ...LinkPainter::marks($link, $placement, $edge, $this->label($index), $style, $background));
    }

    /** The dashed line from a note to the entity it stands beside. */
    private function noteLink(PlantUmlNote $note, Placement $placement): void
    {
        if (null === $note->entity || !\array_key_exists($note->id, $this->notes) || !\array_key_exists($note->entity, $this->boxes)) {
            return;
        }
        $from = $placement->endpoints[$note->id];
        $to = $placement->endpoints[$note->entity];
        $points = BoxClip::clipped([[$from->x, $from->y], [$to->x, $to->y]], $from, $to);
        $this->marks[] = new Mark(MarkKind::Polyline, $points, stroke: '#181818', dashed: true, strokeWidth: 0.5, dashes: [7.0, 7.0]);
    }
}
