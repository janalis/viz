<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Outline;
use Janalis\Viz\PlantUML\Descriptions\DescriptionDrawing;
use Janalis\Viz\PlantUML\Descriptions\DescriptionShapes;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/** A class diagram's package or container as PlantUML draws it: a tabbed frame, or its symbol's outline. */
final class ClassPackage
{
    /** The symbol it is drawn as: the one it is declared as, else its stereotype's, as PlantUML's `USymbols` reads it, lower case; `package` for any other. */
    public static function symbol(ClassGroup $group): string
    {
        if (null !== $group->symbol) {
            return $group->symbol;
        }
        foreach (ClassReader::stereotypes((string) $group->stereotype) as $stereotype) {
            if (\in_array(\strtolower($stereotype), ['node', 'cloud', 'database', 'rectangle'], strict: true)) {
                return \strtolower($stereotype);
            }
        }

        return 'package';
    }

    /**
     * A package's frame with the tab its title sits in, or a container's symbol around it.
     *
     * @param list<string> $signature the style it is drawn in: a package's, or an empty one's, drawn as an element
     * @return list<Mark>
     */
    public static function marks(ClassGroup $group, StyleSheet $styles, float $x0, float $y0, float $x1, float $y1, array $signature = ['root', 'element', 'group', 'package']): array
    {
        $marks = [];
        $style = $styles->style($signature, null === $group->stereotype ? [] : ClassReader::stereotypes($group->stereotype));
        $title = PlantUmlText::styled(ClassReader::split('' === $group->display ? $group->id : $group->display), $styles->style(['root', 'element', 'package', 'title']));
        [$width, $height] = $title->size();
        $symbol = self::symbol($group);
        if (!\in_array($symbol, ['package', 'rectangle'], strict: true)) {
            // As PlantUML draws a package so marked or a container so declared, as a description diagram's container: its symbol's outline, its title in its tab or centred at its top.
            $box = $styles->style(['root', 'element', 'classDiagram', 'group', $symbol]);
            [$line, $back, $thickness] = [$box->color('LineColor', '#181818'), ClassColors::back($group->color) ?? 'none', $box->number('LineThickness', 1.0)];
            \array_push(
                $marks,
                ...'frame' === $symbol
                    ? DescriptionShapes::frame($x0, $y0, $x1 - $x0, $y1 - $y0, $line, $back, $thickness, ...$title->size())
                    : DescriptionShapes::container($symbol, $x0, $y0, $x1 - $x0, $y1 - $y0, $line, $back, $thickness, $box->number('RoundCorner'), $title->size()),
            );
            $tabbed = \in_array($symbol, ['folder', 'frame'], strict: true);
            [$tabX, $tabY] = 'frame' === $symbol ? [3.0, 1.0] : [4.0, 2.0];
            \array_push($marks, ...$title->marks(
                $tabbed ? $x0 + $tabX : $x0 + ('node' === $symbol ? -4.0 : 0.0),
                $y0 + ($tabbed ? $tabY : DescriptionDrawing::TITLE_TOPS[$symbol] ?? 2.0),
                $tabbed ? $width : $x1 - $x0,
                $tabbed ? 'left' : 'center',
            ));

            return $marks;
        }
        if ('rectangle' === $symbol) {
            // As PlantUML's `USymbolRectangle` draws a package so marked: a box, its title centred 2 below its top.
            $box = $styles->style(['root', 'element', 'classDiagram', 'group', 'rectangle']);
            $marks[] = ClassBox::rectangle(
                $x0,
                $y0,
                $x1 - $x0,
                $y1 - $y0,
                $box->color('LineColor', '#181818'),
                ClassColors::back($group->color) ?? $box->color('BackGroundColor', 'none'),
                $box->number('LineThickness', 1.0),
                $box->number('RoundCorner', 5.0),
            );
            \array_push($marks, ...$title->marks($x0, $y0 + 2, $x1 - $x0));

            return $marks;
        }
        $tab = $height + 6;
        $line = $style->color('LineColor', '#000000');
        $stroke = $style->number('LineThickness', 1.5);
        $back = ClassColors::back($group->color) ?? $style->color('BackGroundColor', 'none');
        $marks[] = new Mark(
            MarkKind::Polygon,
            [[$x0, $y0], [$x0 + $width + 12, $y0], [$x0 + $width + 12, $y0 + $tab], [$x1, $y0 + $tab], [$x1, $y1], [$x0, $y1]],
            stroke: $line,
            fill: $back,
            strokeWidth: $stroke,
            // As PlantUML, which draws it as a path, its limit finder taking it as it runs.
            outline: Outline::Path,
        );
        $marks[] = new Mark(MarkKind::Polyline, [[$x0, $y0 + $tab], [$x0 + $width + 12, $y0 + $tab]], stroke: $line, strokeWidth: $stroke);
        \array_push($marks, ...$title->marks($x0 + 4, $y0 + 2, $width));

        return $marks;
    }
}
