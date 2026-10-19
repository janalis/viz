<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Route;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Links\Extremity;
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

        return PlantUmlFrame::drawing($classes->document, $drawing->paint($placement), $placement->graph->width, $placement->graph->height);
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
            $title = new PlantUmlText(['' === $group->display ? $group->id : $group->display], 14.0);
            [$width, $height] = 'together' === $group->type ? [0.0, 0.0] : $title->size();
            $svek->cluster($group->id, $group->parent, 'together' === $group->type ? 0.0 : $width + 12, 'together' === $group->type ? 0.0 : $height + 6);
        }
        $linked = [];
        foreach ($this->classes->links as $link) {
            $linked[$link->from] = true;
            $linked[$link->to] = true;
        }
        foreach ($this->classes->entities as $entity) {
            if ($portions->hidden($entity, \array_key_exists($entity->id, $linked))) {
                continue;
            }
            $box = ClassBox::of($entity, $this->classes->document->styles, $portions, \is_numeric($icons) ? (float) $icons : 10.0);
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
            $this->edges[$index] = $svek->edge($link->from, $link->to, $link->length, $width > 0 ? $width + 8 : 0.0, $height > 0 ? $height + 8 : 0.0, !$link->norank);
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
    private function label(int $index): PlantUmlText
    {
        $link = $this->classes->links[$index];
        $lines = null === $link->label ? [] : ClassReader::split($link->label);
        foreach ($this->classes->notes as $note) {
            $lines = $note->link === $index ? [...$lines, ...$note->lines] : $lines;
        }

        return PlantUmlText::styled($lines, $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'arrow']));
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
        $title = PlantUmlText::styled(['' === $group->display ? $group->id : $group->display], $this->classes->document->styles->style(['root', 'element', 'package', 'title']));
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
        );
        $this->marks[] = new Mark(MarkKind::Polyline, [[$x0, $y0 + $tab], [$x0 + $width + 12, $y0 + $tab]], stroke: $line, strokeWidth: $stroke);
        \array_push($this->marks, ...$title->marks($x0 + 6, $y0 + 3, $width));
    }

    private function link(ClassLink $link, Placement $placement, int $edge, int $index): void
    {
        if ($link->hidden) {
            return;
        }
        $from = $placement->endpoints[$link->from];
        $to = $placement->endpoints[$link->to];
        $route = $placement->routes[$edge] ?? [[$from->x, $from->y], [$to->x, $to->y]];
        $points = BoxClip::clipped(Route::smooth($route), $from, $to);
        $style = $this->classes->document->styles->style(['root', 'element', 'classDiagram', 'arrow']);
        $color = ClassColors::back($link->color) ?? $style->color('LineColor', '#181818');
        $stroke = $link->thickness ?? ($link->bold ? 2.0 : $style->number('LineThickness', 1.0));
        $background = $this->classes->document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        foreach ([[$link->toDecor, false], [$link->fromDecor, true]] as [$decor, $reversed]) {
            $points = $reversed ? \array_reverse($points) : $points;
            [$tip, $along] = Route::end($points);
            [$marks, $short] = Extremity::marks($decor, $tip, $along, $color, 'none' === $background ? '#ffffff' : $background, $stroke);
            \array_push($this->marks, ...$marks);
            $points = $short > 0 ? Route::shorten($points, $short)[0] : $points;
            $points = $reversed ? \array_reverse($points) : $points;
        }
        $this->marks[] = new Mark(
            MarkKind::Polyline,
            $points,
            stroke: $color,
            dashed: $link->dashed || $link->dotted,
            strokeWidth: $stroke,
            dashes: self::dashes($link),
        );
        $label = $this->label($index);
        if (!$label->empty()) {
            [$width, $height] = $label->size();
            [$x, $y] = $placement->labels[$edge] ?? $points[\intdiv(\count($points), num2: 2)];
            \array_push($this->marks, ...$label->marks($x - ($width / 2), $y - ($height / 2), $width));
            $this->labelArrow($link, $from, $to, $x - ($width / 2) - 8, $y, $color);
        }
        foreach ([[$link->firstLabel, $points], [$link->secondLabel, \array_reverse($points)]] as [$text, $path]) {
            $this->endLabel($text, $path, $style->fontSize());
        }
    }

    /** The small triangle before a label written `label >` or `< label`, pointing the way the label reads. */
    private function labelArrow(ClassLink $link, GraphNode $from, GraphNode $to, float $x, float $y, string $color): void
    {
        if (null === $link->labelArrow) {
            return;
        }
        [$dx, $dy] = '>' === $link->labelArrow ? [$to->x - $from->x, $to->y - $from->y] : [$from->x - $to->x, $from->y - $to->y];
        [$dx, $dy] = \abs($dx) > \abs($dy) ? [$dx <=> 0, 0] : [0, $dy <=> 0];
        $this->marks[] = new Mark(
            MarkKind::Polygon,
            [[$x + (4 * $dx), $y + (4 * $dy)], [$x - (4 * $dx) - (3 * $dy), $y - (4 * $dy) + (3 * $dx)], [$x - (4 * $dx) + (3 * $dy), $y - (4 * $dy) - (3 * $dx)]],
            stroke: $color,
            fill: $color,
            strokeWidth: 0.5,
        );
    }

    /**
     * A label at a link's end, set beside the line a little way from the entity.
     *
     * @param list<array{float, float}> $path from that end
     */
    private function endLabel(?string $text, array $path, float $size): void
    {
        if (null === $text || \count($path) < 2) {
            return;
        }
        $label = new PlantUmlText(ClassReader::split($text), $size);
        [$width, $height] = $label->size();
        [$x0, $y0] = $path[0];
        [$x1, $y1] = $path[1];
        $length = \max(1e-9, \hypot($x1 - $x0, $y1 - $y0));
        [$dx, $dy] = [($x1 - $x0) / $length, ($y1 - $y0) / $length];
        $x = $x0 + ($dx * 12) + ($dy * (($width / 2) + 4));
        $y = $y0 + ($dy * 12) - ($dx * (($height / 2) + 2));
        \array_push($this->marks, ...$label->marks($x - ($width / 2), $y - ($height / 2), $width));
    }

    /** The dashed line from a note to the entity it stands beside. */
    private function noteLink(ClassNote $note, Placement $placement): void
    {
        if (null === $note->entity || !\array_key_exists($note->id, $this->notes) || !\array_key_exists($note->entity, $this->boxes)) {
            return;
        }
        $from = $placement->endpoints[$note->id];
        $to = $placement->endpoints[$note->entity];
        $points = BoxClip::clipped([[$from->x, $from->y], [$to->x, $to->y]], $from, $to);
        $this->marks[] = new Mark(MarkKind::Polyline, $points, stroke: '#181818', dashed: true, strokeWidth: 0.5, dashes: [7.0, 7.0]);
    }

    /** @return ?list<float> */
    private static function dashes(ClassLink $link): ?array
    {
        return match (true) {
            $link->dotted => [1.0, 3.0],
            $link->dashed => [7.0, 7.0],
            default => null,
        };
    }
}
