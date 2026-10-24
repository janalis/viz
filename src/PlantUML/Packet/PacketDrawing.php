<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Packet;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Draw\Shadow;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Source\Unsupported;

/**
 * A packet diagram laid out and drawn as PlantUML's `PacketDiagram` does: its fields in rows
 * of so many bits, a field running past a row's end carried on to the next rows; each bit as
 * wide as three times the font size; a scale above, a tick at each bit — long every quarter
 * row, numbered at every interval — each field a box as tall as its height in bits, at least
 * its text and 10 above and below, its text centred, cut short with `...` where too wide.
 */
final class PacketDrawing
{
    /** A long tick's length; a short one is half as long, half way down. */
    private const float TICK = 32.0;
    private const float MARGIN = 10.0;

    /** @var list<Mark> */
    private array $marks = [];

    private function __construct(
        private readonly PlantUmlPacket $diagram,
        private readonly Style $style,
        private readonly Style $box,
        private readonly float $bitWidth,
    ) {}

    /** @throws Unsupported where PlantUML fails: a first row no bit wide */
    public static function draw(PlantUmlPacket $diagram): Drawing
    {
        $styles = $diagram->document->styles;
        $style = $styles->style(['root', 'element', 'packetdiagDiagram']);
        $drawing = new self($diagram, $style, $styles->style(['root', 'element', 'packetdiagDiagram', 'rectangle']), $style->fontSize() * 3);
        [$width, $height] = $drawing->paint();

        return PlantUmlFrame::drawing($diagram->document, $drawing->marks, $width, $height);
    }

    /**
     * @return array{float, float} the size PlantUML gives the diagram: the scale's and the rows'
     * @throws Unsupported where PlantUML fails: a first row no bit wide
     */
    private function paint(): array
    {
        $diagram = $this->diagram;
        $interval = $diagram->interval ?? ($diagram->columns >= 2 ? \intdiv($diagram->columns, num2: 2) : 8);
        $rows = $this->rows();
        // As PlantUML, a row no wider than the first's bits.
        $first = 0;
        foreach ($rows[0] ?? [] as [$bitsWide]) {
            $first += $bitsWide;
        }
        if ([] !== $rows && $first <= 0) {
            throw new Unsupported('PlantUML fails on a packet whose first row is no bit wide');
        }
        $columns = [] === $rows ? $diagram->columns : \min($first, $diagram->columns);
        $quarter = $columns >= 4 ? \intdiv($columns, num2: 4) : $columns;
        $numberHeight = PlantUmlText::lineHeight($this->style->fontSize());
        $scaleHeight = 0.0;
        $bits = $diagram->rightToLeft ? \range($columns, end: 0) : \range(0, $columns);
        foreach ($bits as $index => $bit) {
            $length = 0 === ($bit % $quarter) ? self::TICK : self::TICK / 2;
            $scaleHeight = \max($scaleHeight, $numberHeight + $length);
            $this->tick($index * $this->bitWidth, $length, $interval > 0 && 0 === ($bit % $interval) ? (string) $bit : null, $numberHeight);
        }
        $width = \count($bits) * $this->bitWidth;
        $top = $scaleHeight;
        foreach ($rows as $row) {
            $left = 0.0;
            $rowHeight = 0.0;
            foreach ($row as [$bitsWide, $tall, $text]) {
                $rowHeight = $this->field($left, $top, $bitsWide * $this->bitWidth, $tall * $diagram->bitHeight, $text);
                $left += $bitsWide * $this->bitWidth;
            }
            $width = \max($width, $left);
            $top += $rowHeight;
        }

        return [$width, $top];
    }

    /**
     * The fields in rows, as PlantUML's `adjustLayout` lays them out: a field too long for the
     * rest of its row cut there and carried on, a whole row at a time, then into the next row.
     *
     * @return list<list<array{int, int, string}>> each row's blocks: their bits, height and text
     */
    private function rows(): array
    {
        $columns = $this->diagram->columns;
        $rows = [];
        $row = [];
        $remaining = $columns;
        foreach ($this->diagram->fields as [$start, $end, $tall, $text]) {
            $bits = $end - $start + 1;
            if ($bits <= $remaining) {
                $row[] = [$bits, $tall, $text];
                $remaining -= $bits;
                if (0 === $remaining) {
                    [$rows[], $row, $remaining] = [$row, [], $columns];
                }
                continue;
            }
            $row[] = [$remaining, $tall, $text];
            $carried = $bits - $remaining;
            [$rows[], $row, $remaining] = [$row, [], $columns];
            for ($full = \intdiv($carried, $columns); $full > 0; --$full) {
                $rows[] = [[\min($columns, $carried), $tall, $text]];
            }
            $rest = $carried % $columns;
            if ($rest > 0) {
                // As PlantUML, the carried block as wide as a whole row would be, though it takes only the rest.
                $row[] = [\min($columns, $carried), $tall, $text];
                $remaining -= $rest;
            }
        }
        if ([] !== $row) {
            $rows[] = $row;
        }

        $laid = [];
        foreach ($rows as $blocks) {
            $tallest = 1;
            foreach ($blocks as [, $tall]) {
                $tallest = \max($tallest, $tall);
            }
            $row = [];
            foreach ($blocks as [$bitsWide, $tall, $text]) {
                $row[] = [$bitsWide, $this->diagram->sameHeight ? $tallest : $tall, $text];
            }
            $laid[] = $this->diagram->rightToLeft ? \array_reverse($row) : $row;
        }

        return $laid;
    }

    /** A tick of the scale, a long or a short one, its number — if it has one — above it centred on it. */
    private function tick(float $x, float $length, ?string $bit, float $numberHeight): void
    {
        $offset = self::TICK - $length;
        if (null !== $bit) {
            $number = new PlantUmlText([$bit], $this->style->fontSize(), $this->style->face(), $this->style->color('FontColor', '#000000'));
            $numberWidth = $number->size()[0];
            \array_push($this->marks, ...$number->marks($x - ($numberWidth / 2), $offset, $numberWidth, 'left'));
        }
        $line = $this->style->color('LineColor', '#181818');
        if ('none' === $line) {
            return;
        }
        $this->marks[] = new Mark(
            MarkKind::Polyline,
            [[$x, $numberHeight + $offset], [$x, $numberHeight + $offset + $length]],
            stroke: $line,
            strokeWidth: $this->style->number('LineThickness', 0.5),
        );
    }

    /** @return float the field's height: its own, or its text's with 10 above and below */
    private function field(float $x, float $y, float $width, float $tall, string $text): float
    {
        $label = PlantUmlText::styled(ClassReader::split($this->shortened($text, $width)), $this->box);
        [, $labelHeight] = $label->size();
        $height = \max($tall, $labelHeight + (2 * self::MARGIN));
        // As PlantUML, a field of no bits, or fewer, is not drawn.
        if ($width <= 0) {
            return $height;
        }
        $box = ClassBox::rectangle(
            $x,
            $y,
            $width,
            $height,
            $this->box->color('LineColor', '#181818'),
            $this->box->color('BackGroundColor', '#f1f1f1'),
            $this->box->number('LineThickness', 0.5),
            $this->box->number('RoundCorner'),
        );
        \array_push(
            $this->marks,
            ...Shadow::cast([$box], $this->box->number('Shadowing')),
            ...$label->marks($x, $y + self::MARGIN + (($height - $labelHeight - (2 * self::MARGIN)) / 2), $width, 'center'),
        );

        return $height;
    }

    /**
     * As PlantUML's `getLabelTextBlockAbbr`: the text cut a letter at a time, `...` after it,
     * until it and another `...` fit — measured in PlantUML's own font, to cut where it cuts.
     */
    private function shortened(string $text, float $width): string
    {
        $measure = fn (string $part): float => PlantUmlMetrics::width($part, $this->box->fontSize(), $this->box->face());
        $textWidth = $measure($text);
        if ($textWidth < $width) {
            return $text;
        }
        $dots = $measure('...');
        $shortened = $text;
        for ($length = \mb_strlen($text); $length > 0; --$length) {
            if (($textWidth + $dots) < $width) {
                break;
            }
            $shortened = \mb_substr($text, start: 0, length: $length).'...';
            $textWidth = $measure($shortened);
        }

        return $shortened;
    }
}
