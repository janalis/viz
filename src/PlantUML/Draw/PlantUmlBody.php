<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;

/**
 * A note's text as PlantUML's `BodyEnhanced2` sets it: in sections, each after a separator line
 * — `--`, `==`, `..` or `__` at both its ends, a title between them — drawn as a line across
 * its box: 4 above the section and 4 below it, or a titled line the title's height above it,
 * the title in its middle.
 */
final readonly class PlantUmlBody
{
    /** @var list<array{string, ?PlantUmlText, PlantUmlText}> each section's separator, '' for the first, its title and its text */
    private array $sections;

    public function __construct(
        private PlantUmlText $text,
    ) {
        $sections = [];
        [$separator, $title, $lines] = ['', null, []];
        foreach ($text->lines as $line) {
            if (!self::separates($line)) {
                $lines[] = $line;
                continue;
            }
            $sections[] = [$separator, $title, $this->with($lines)];
            [$separator, $title, $lines] = [$line[0], \strlen($line) <= 4 ? null : $this->with([\trim(\substr($line, offset: 2, length: -2))]), []];
        }
        $sections[] = [$separator, $title, $this->with($lines)];
        $this->sections = $sections;
    }

    /**
     * Whether any of the lines separates sections.
     *
     * @param list<string> $lines
     */
    public static function sectioned(array $lines): bool
    {
        return [] !== \array_filter($lines, self::separates(...));
    }

    /** Whether a line separates sections, as PlantUML's `isBlockSeparator` takes one. */
    private static function separates(string $line): bool
    {
        foreach (['--', '==', '..', '__'] as $ends) {
            if (\str_starts_with($line, $ends) && \str_ends_with($line, $ends) && '...' !== $line) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $lines */
    private function with(array $lines): PlantUmlText
    {
        return new PlantUmlText($lines, $this->text->size, $this->text->face, $this->text->color, $this->text->sprites, $this->text->background);
    }

    /** @return array{float, float} */
    public function size(): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($this->sections as $section) {
            [$sectionWidth, $sectionHeight] = self::measured($section);
            [$width, $height] = [\max($width, $sectionWidth), $height + $sectionHeight];
        }

        return [$width, $height];
    }

    /**
     * A section's size: its text's alone; with a line, 4 more above and below; with a titled
     * line, 6 more to the right and the title's height above, 8 wider than the title at least.
     *
     * @param array{string, ?PlantUmlText, PlantUmlText} $section
     * @return array{float, float}
     */
    private static function measured(array $section): array
    {
        [$separator, $title, $text] = $section;
        [$width, $height] = $text->size();
        if (null === $title) {
            return '' === $separator ? [$width, $height] : [$width, $height + 8];
        }
        [$titleWidth, $titleHeight] = $title->size();

        return [\max($width + 6, $titleWidth + 8), ($titleHeight / 2) + \max(($titleHeight / 2) + $height + 4, $titleHeight)];
    }

    /**
     * The sections' marks, the first's top left at a point, their lines across from one point to
     * another, 1 in from each.
     *
     * @return list<Mark>
     */
    public function marks(float $x, float $y, float $width, string $align, float $from, float $to, string $color, float $thickness): array
    {
        $marks = [];
        foreach ($this->sections as $section) {
            [$separator, $title, $text] = $section;
            $height = self::measured($section)[1];
            $line = null === $title ? $y : $y + ($title->size()[1] / 2);
            $top = match (true) {
                '' === $separator => $y,
                null === $title => $y + 4,
                default => $y + $title->size()[1],
            };
            if ('' !== $separator) {
                \array_push($marks, ...self::line($separator, $title, $from + 1, $to - 1, $line, $color, $thickness));
            }
            \array_push($marks, ...$text->marks($x, $top, $width, $align));
            $y += $height;
        }

        return $marks;
    }

    /**
     * A separator line, as PlantUML's `UHorizontalLine` draws it: `=` doubled 2 below, `.`
     * dotted, `_` as thick as the note's border; a title in its middle, half a point above it.
     *
     * @return list<Mark>
     */
    private static function line(string $separator, ?PlantUmlText $title, float $from, float $to, float $y, string $color, float $thickness): array
    {
        $titleWidth = null === $title ? 0.0 : $title->size()[0];
        $gap = ($to - $from - $titleWidth) / 2;
        $spans = null === $title ? [[$from, $to]] : [[$from, $from + $gap], [$to - $gap, $to]];
        $marks = [];
        foreach ([$y, ...('=' === $separator ? [$y + 2] : [])] as $at) {
            foreach ($spans as [$left, $right]) {
                $marks[] = new Mark(
                    MarkKind::Polyline,
                    [[$left, $at], [$right, $at]],
                    stroke: $color,
                    dashed: '.' === $separator,
                    strokeWidth: '_' === $separator ? $thickness : 1.0,
                    dashes: '.' === $separator ? [1.0, 2.0] : null,
                );
            }
        }
        if (null !== $title) {
            \array_push($marks, ...$title->marks($from + $gap, $y - ($title->size()[1] / 2) - 0.5, $titleWidth, 'left'));
        }

        return $marks;
    }
}
