<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\BoxClip;
use Janalis\Viz\PlantUML\Draw\LinkLabel;
use Janalis\Viz\PlantUML\Draw\LinkPainter;
use Janalis\Viz\PlantUML\Draw\NoteBox;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\StackedText;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
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
    /** @var array<string, NoteBox> */
    private array $notes = [];
    /** @var array<int, int> */
    private array $edges = [];

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

        return PlantUmlFrame::svek($diagram->document, $drawing->paint($placement));
    }

    /** The room PlantUML's symbols keep around a container's title for their shape: a node's depth, a database's lid. */
    private const array SHAPE_ROOM = ['node' => [60.0, 5.0], 'database' => [0.0, 15.0]];

    /** How far below a container's top its title stands, where PlantUML sets it otherwise than in a plain box: 11 lower, 10 in a node, 19 under a database's lid, 5 in storage, 1 higher in a file or a queue. */
    private const array TITLE_TOPS = ['node' => 12.0, 'cloud' => 13.0, 'component' => 13.0, 'stack' => 13.0, 'database' => 21.0, 'storage' => 7.0, 'file' => 1.0, 'queue' => 1.0];

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
        foreach ($this->diagram->elements as $element) {
            $box = DescriptionBox::of($element, $this->diagram->document->styles);
            $this->boxes[$element->id] = $box;
            $svek->node($element->id, $box->width, $box->height, $element->group);
        }
        $style = $this->diagram->document->styles->style(['root', 'element', 'componentDiagram', 'note']);
        foreach ($this->diagram->notes as $note) {
            if (null !== $note->link) {
                continue;
            }
            $box = new NoteBox(PlantUmlText::styled($note->lines, $style), $style, ClassColors::back($note->color));
            $this->notes[$note->id] = $box;
            [$width, $height] = $box->size();
            $svek->node($note->id, $width, $height, $note->group);
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
        foreach ($this->diagram->notes as $note) {
            if (null === $note->entity) {
                continue;
            }
            $across = \in_array($note->position, ['left', 'right'], strict: true) ? 1 : 2;
            \in_array($note->position, ['left', 'top'], strict: true) ? $svek->edge($note->id, $note->entity, $across) : $svek->edge($note->entity, $note->id, $across);
        }

        return $svek;
    }

    /** A group's title: its stereotypes in their style, as PlantUML draws them above its name. */
    private function title(DescriptionGroup $group): StackedText
    {
        $styles = $this->diagram->document->styles;
        $texts = [];
        if ([] !== $group->stereotypes && $group->stereotypeShown) {
            $style = $styles->style(['root', 'element', 'componentDiagram', $group->symbol, 'stereotype'], $group->stereotypes);
            $texts[] = PlantUmlText::styled(\array_map(static fn (string $stereotype): string => '«'.$stereotype.'»', $group->stereotypes), $style);
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

    /** @return list<Mark> */
    private function paint(Placement $placement): array
    {
        $marks = [];
        foreach ($placement->graph->clusters as $box) {
            $group = $this->diagram->groups[$box->name];
            if ('together' === $group->symbol) {
                continue;
            }
            $style = $this->diagram->document->styles->style(['root', 'element', 'componentDiagram', $group->symbol], $group->stereotypes);
            $line = ClassColors::line($group->color) ?? $style->color('LineColor', '#181818');
            $back = ClassColors::back($group->color) ?? 'none';
            $title = $this->title($group);
            [$width, $height, $thickness] = [$box->x1 - $box->x0, $box->y1 - $box->y0, $style->number('LineThickness', 1.0)];
            \array_push(
                $marks,
                ...'frame' === $group->symbol
                    ? DescriptionShapes::frame($box->x0, $box->y0, $width, $height, $line, $back, $thickness, ...$title->size())
                    : DescriptionShapes::outline($group->symbol, $box->x0, $box->y0, $width, $height, $line, $back, $thickness, 0.0),
            );
            // A package's, a folder's or a frame's title sits in its tab; any other container's is centred at its top, lower for the symbols PlantUML draws it lower in.
            $tabbed = \in_array($group->symbol, ['package', 'folder', 'frame'], strict: true);
            [$tabX, $tabY] = 'frame' === $group->symbol ? [3.0, 1.0] : [4.0, 1.0];
            \array_push($marks, ...$title->marks(
                $tabbed ? $box->x0 + $tabX : $box->x0 + ('node' === $group->symbol ? -4.0 : 0.0),
                $box->y0 + ($tabbed ? $tabY : self::TITLE_TOPS[$group->symbol] ?? 2.0),
                $tabbed ? $title->size()[0] : $box->x1 - $box->x0,
                $tabbed ? 'left' : 'center',
            ));
        }
        $style = $this->diagram->document->styles->style(['root', 'element', 'componentDiagram', 'arrow']);
        $background = $this->diagram->document->styles->style(['root', 'document'])->color('BackGroundColor', '#ffffff');
        foreach ($this->edges as $index => $edge) {
            \array_push($marks, ...LinkPainter::marks($this->diagram->links[$index], $placement, $edge, $this->label($index), $style, $background));
        }
        foreach ($this->diagram->notes as $note) {
            if (null === $note->entity || !\array_key_exists($note->id, $this->notes)) {
                continue;
            }
            $from = $placement->endpoints[$note->id];
            $to = $placement->endpoints[$note->entity];
            $marks[] = new Mark(MarkKind::Polyline, BoxClip::clipped([[$from->x, $from->y], [$to->x, $to->y]], $from, $to), stroke: '#181818', dashed: true, strokeWidth: 0.5, dashes: [7.0, 7.0]);
        }
        foreach ($this->boxes as $id => $box) {
            $node = $placement->endpoints[$id];
            \array_push($marks, ...$box->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }
        foreach ($this->notes as $id => $note) {
            $node = $placement->endpoints[$id];
            \array_push($marks, ...$note->marks($node->x - ($node->w / 2), $node->y - ($node->h / 2)));
        }

        return $marks;
    }
}
