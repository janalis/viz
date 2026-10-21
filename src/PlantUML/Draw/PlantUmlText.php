<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\DisplayWidth;
use Janalis\Viz\Text\FontFace;

/**
 * PlantUML text in the bundled font, in points: lines of Creole with their markup taken out —
 * a line wholly bold or italic drawn so, a heading — one to three = before it — bigger and bold,
 * a sprite (`<$name>`) drawn in its place as PlantUML draws one — measured, and set as text
 * marks left, centred or right within a width.
 */
final readonly class PlantUmlText
{
    /**
     * A line's height for each point of font size, as PlantUML measures its default font:
     * Java's SansSerif, 11.14 ascent and 2.83 descent at 12 points.
     */
    private const float LINE = 1.164_062_5;

    private const string TAGS = '/<\/?(?:b|i|u|s|w|strike|sub|sup|color(?::[^>]*)?|back(?::[^>]*)?|size(?::[^>]*)?|font(?:[: ][^>]*)?|plain|code)>|<&[\w-]+>|'.self::SPRITE.'/iu';

    /** A sprite in Creole, as PlantUML's `Splitter` finds one: its colour, name, and scale or colour. */
    private const string SPRITE = '<(#\w+)?\$([-\p{L}0-9_\/]+)([{,]?(?:(?:scale=|\*)[0-9.]+)?(?:,?color[= :](?:#[0-9a-fA-F]{1,8}|\w+))?\}?)>';

    /**
     * @param list<string> $lines Creole, a line each
     * @param array<string, PlantUmlSprite> $sprites the document's, by name
     * @param string $background the colour behind the text, which a sprite's faint levels shade towards
     */
    public function __construct(
        public array $lines,
        public float $size = 14.0,
        public FontFace $face = FontFace::Regular,
        public string $color = '#000000',
        public array $sprites = [],
        public string $background = '#ffffff',
    ) {}

    /**
     * Text in a style: its font, colour and background, wrapped at its `MaximumWidth` — as
     * `skinparam wrapWidth` sets it — where PlantUML wraps.
     *
     * @param list<string> $lines
     */
    public static function styled(array $lines, Style $style, ?FontFace $face = null): self
    {
        $text = new self($lines, $style->fontSize(), $face ?? $style->face(), $style->color('FontColor', '#000000'), $style->sprites, $style->color('BackGroundColor', '#ffffff'));
        $maximum = $style->number('MaximumWidth');

        return $maximum > 0 ? new self($text->wrapped($maximum), $text->size, $text->face, $text->color, $text->sprites, $text->background) : $text;
    }

    /**
     * The lines wrapped as PlantUML's `Fission` wraps them: a word goes on the next line when
     * it would take its line past the width, as PlantUML measures it, and a word alone stays
     * however wide; a heading's lines stay headings, and markup open at a break is closed
     * there and opened again after it.
     *
     * @return list<string>
     */
    private function wrapped(float $maximum): array
    {
        $wrapped = [];
        foreach ($this->lines as $line) {
            if (PlantUmlTable::row($line)) {
                $wrapped[] = $line;
                continue;
            }
            [$depth, $text] = self::heading($line);
            $marker = $depth < 0 ? '' : \str_repeat('=', times: $depth + 1).' ';
            $face = self::face($depth < 0 ? $text : '**'.$text.'**', $this->face);
            $size = $this->fontSize($line);
            $pieces = [];
            foreach (\explode(' ', $text) as $word) {
                $last = \array_key_last($pieces);
                $candidate = null === $last ? $word : $pieces[$last].' '.$word;
                if (null !== $last && self::measured($candidate, $size, $face) > $maximum) {
                    $pieces[] = $word;
                    continue;
                }
                $pieces[null === $last ? 0 : $last] = $candidate;
            }
            \array_push($wrapped, ...\array_map(static fn (string $piece): string => $marker.$piece, self::balanced($pieces)));
        }

        return $wrapped;
    }

    /** A stretch of Creole as PlantUML measures it, each part at the size its `<size:…>` gives it. */
    private static function measured(string $text, float $size, FontFace $face): float
    {
        $width = 0.0;
        $sizes = [$size];
        foreach ((array) \preg_split('/(<\/?size(?::[^>]*)?>)/i', $text, flags: \PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            $part = (string) $part;
            if (0 === ($index % 2)) {
                // A piece of a line may hold markup it does not close, as wrapping cuts it: every marker comes out.
                $width += PlantUmlMetrics::width(\str_replace(['**', '//', '""', '__', '~~'], replace: '', subject: self::plain($part)), $sizes[\count($sizes) - 1], $face);
                continue;
            }
            $match = [];
            if (1 === \preg_match('/^<size:\s*([0-9.]+)\s*>$/i', $part, $match) && \is_numeric($match[1])) {
                $sizes[] = (float) $match[1];
                continue;
            }
            if (\count($sizes) > 1) {
                \array_pop($sizes);
            }
        }

        return $width;
    }

    /**
     * Pieces of a line with the markup open at the end of each closed there and opened again
     * at the start of the next.
     *
     * @param list<string> $pieces
     * @return list<string>
     */
    private static function balanced(array $pieces): array
    {
        /** @var list<string> $openers each markup open, as it opens */
        $openers = [];
        /** @var list<string> $closers and as it closes */
        $closers = [];
        $balanced = [];
        foreach ($pieces as $piece) {
            /** @var list<string> $openers */
            $piece = \implode('', $openers).$piece;
            $openers = [];
            $closers = [];
            $marks = [];
            \preg_match_all('/\*\*|\/\/|__|""|~~|<(\/?)(b|i|u|s|w|strike|sub|sup|color|back|size|font)\b[^>]*>/i', $piece, $marks, \PREG_SET_ORDER);
            /** @var list<array<int, string>> $marks */
            foreach ($marks as $mark) {
                $tag = \strtolower($mark[2] ?? '');
                $close = '' === $tag ? $mark[0] : '</'.$tag.'>';
                $index = \array_search($close, $closers, strict: true);
                if (false !== $index && ('' === $tag || '/' === $mark[1])) {
                    $openers = [...\array_slice($openers, offset: 0, length: $index), ...\array_slice($openers, $index + 1)];
                    $closers = [...\array_slice($closers, offset: 0, length: $index), ...\array_slice($closers, $index + 1)];
                    continue;
                }
                if ('/' !== ($mark[1] ?? '')) {
                    $openers[] = $mark[0];
                    $closers[] = $close;
                }
            }
            /** @var list<string> $closers */
            $balanced[] = $piece.\implode('', \array_reverse($closers));
        }

        return $balanced;
    }

    /** A line without its Creole markup: a `**`, `//`, `""`, `__` or `~~` only around something, as PlantUML reads it, `****` written as it is. */
    public static function plain(string $line): string
    {
        $line = (string) \preg_replace(self::TAGS, replacement: '', subject: $line);

        return (string) \preg_replace('/(\*\*|\/\/|""|__|~~)(.+?)\1/u', replacement: '$2', subject: $line);
    }

    public function empty(): bool
    {
        return [] === $this->lines || (1 === \count($this->lines) && '' === \trim($this->lines[0]));
    }

    /** @return array{float, float} */
    public function size(): array
    {
        if ($this->empty()) {
            return [0.0, 0.0];
        }
        $widest = 0.0;
        $height = 0.0;
        foreach ($this->blocks() as $block) {
            [$blockWidth, $blockHeight] = $block instanceof PlantUmlTable ? $block->size() : $this->lineSize($this->atoms($block), $this->fontSize($block));
            $widest = \max($widest, $blockWidth);
            $height += $blockHeight;
        }

        return [$widest, $height];
    }

    /**
     * The lines, each run of table rows in it a table, as PlantUML's Creole reads them.
     *
     * @return list<string|PlantUmlTable>
     */
    private function blocks(): array
    {
        $blocks = [];
        $rows = [];
        foreach ([...$this->lines, null] as $line) {
            if (null !== $line && PlantUmlTable::row($line)) {
                $rows[] = $line;
                continue;
            }
            if ([] !== $rows) {
                $blocks[] = PlantUmlTable::of($rows, $this);
                $rows = [];
            }
            if (null !== $line) {
                $blocks[] = $line;
            }
        }

        return $blocks;
    }

    /**
     * As PlantUML's Creole reads a heading, `=` signs and text: its depth from 0, and its text;
     * -1 and the line itself for any other, a section title (`==title==`) among them.
     *
     * @return array{int, string}
     */
    private static function heading(string $line): array
    {
        $match = [];
        if (1 !== \preg_match('/^(=+)(.+)$/', $line, $match) || 1 === \preg_match('/^==[^=]*==$|^===*==$/', $line)) {
            return [-1, $line];
        }

        return [\strlen($match[1]) - 1, \trim($match[2])];
    }

    /** A line's font size: a heading's bigger, as PlantUML makes it. */
    private function fontSize(string $line): float
    {
        return (
            $this->size
            + match (self::heading($line)[0]) {
                0 => 4.0,
                1 => 2.0,
                2 => 1.0,
                default => 0.0,
            }
        );
    }

    /**
     * A line's text and sprites in turn, as PlantUML's Creole sets them side by side: a sprite
     * scaled to the font, as its size over 13; one the document does not define, left out.
     *
     * @return list<array{string, ?PlantUmlSprite, float, ?string}> each atom's text, or its sprite with its scale and forced colour
     */
    private function atoms(string $line): array
    {
        $text = self::heading($line)[1];
        $size = $this->fontSize($line);
        $parts = (array) \preg_split('/'.self::SPRITE.'/u', $text, flags: \PREG_SPLIT_DELIM_CAPTURE);
        $atoms = [];
        for ($at = 0, $count = \count($parts); $at < $count; $at += 4) {
            if ('' !== (string) $parts[$at]) {
                $atoms[] = [(string) $parts[$at], null, 0.0, null];
            }
            $sprite = $this->sprites[(string) ($parts[$at + 2] ?? '')] ?? null;
            if (null === $sprite) {
                continue;
            }
            $atoms[] = ['', $sprite, (self::scale((string) $parts[$at + 3]) * $size) / 13.0, self::tint((string) $parts[$at + 1], (string) $parts[$at + 3])];
        }

        return $atoms;
    }

    /** A sprite's scale, as `scale=` or `*` writes it. */
    private static function scale(string $options): float
    {
        $match = [];

        return 1 === \preg_match('/(?:scale=|\*)([0-9.]+)/', $options, $match) && \is_numeric($match[1]) ? (float) $match[1] : 1.0;
    }

    /** A sprite's colour, forced before its `$` or as `color=`; null for the text's. */
    private static function tint(string $before, string $options): ?string
    {
        $match = [];
        if ('' !== $before) {
            return $before;
        }

        return 1 === \preg_match('/color[= :](#[0-9a-fA-F]{1,6}|\w+)/', $options, $match) ? $match[1] : null;
    }

    /**
     * @param list<array{string, ?PlantUmlSprite, float, ?string}> $atoms
     * @return array{float, float} a line's width, and its height: its text's, or its tallest sprite's
     */
    private function lineSize(array $atoms, float $size): array
    {
        $width = 0.0;
        $height = self::lineHeight($size);
        foreach ($atoms as [$text, $sprite, $scale]) {
            $width += null === $sprite ? DisplayWidth::of(self::plain($text)) * Units::cell($size) : $sprite->width * $scale;
            $height = \max($height, null === $sprite ? 0.0 : $sprite->height * $scale);
        }

        return [$width, $height];
    }

    public function line(): float
    {
        return self::lineHeight($this->size);
    }

    /** A line's height at a font size in points, as PlantUML measures its default font. */
    public static function lineHeight(float $size): float
    {
        return $size * self::LINE;
    }

    /**
     * The text's marks, its block's top left at a point, each line set left, centred or right
     * within a width, its text and sprites standing on the line's foot.
     *
     * @return list<Mark>
     */
    public function marks(float $x, float $y, float $width, string $align = 'center'): array
    {
        $marks = [];
        $top = $y;
        foreach ($this->blocks() as $line) {
            if ($line instanceof PlantUmlTable) {
                [$tableWidth, $tableHeight] = $line->size();
                \array_push($marks, ...$line->marks('left' === $align ? $x : $x + (($width - $tableWidth) / ('right' === $align ? 1 : 2)), $top));
                $top += $tableHeight;
                continue;
            }
            $size = $this->fontSize($line);
            $atoms = $this->atoms($line);
            [$shown, $height] = $this->lineSize($atoms, $size);
            $foot = $top + $height;
            $top = $foot;
            $left = match ($align) {
                'left' => $x,
                'right' => $x + $width - $shown,
                default => $x + (($width - $shown) / 2),
            };
            foreach ($atoms as [$text, $sprite, $scale, $color]) {
                [$mark, $advance] = null === $sprite ? $this->word($line, $text, $size, $left, $foot) : $this->picture($sprite, $scale, $color, $left, $foot);
                \array_push($marks, ...$mark);
                $left += $advance;
            }
        }

        return $marks;
    }

    /** @return array{list<Mark>, float} a stretch of text's mark, standing on a line's foot, and its width */
    private function word(string $line, string $text, float $size, float $left, float $foot): array
    {
        $plain = self::plain($text);
        $shown = DisplayWidth::of($plain) * Units::cell($size);
        // As PlantUML, which writes no text of a transparent colour.
        if ('' === \trim($plain) || 'none' === $this->color) {
            return [[], $shown];
        }
        $depth = self::heading($line)[0];
        $match = [];
        // A line wholly coloured, as `<color:…>` around it writes it, takes that colour.
        $color = 1 === \preg_match('/^\s*<color:([^>]+)>.*<\/color>\s*$/i', $line, $match) ? PlantUmlColor::parse($match[1]) ?? $this->color : $this->color;
        $mark = new Mark(
            MarkKind::Text,
            [[$left + ($shown / 2), $foot - (0.25 * self::lineHeight($size))]],
            $plain,
            stroke: $color,
            font: self::face(match (true) {
                $depth < 0 => self::heading($line)[1],
                $depth < 3 => '**'.$text.'**',
                default => '//'.$text.'//',
            }, $this->face),
            fontScale: Units::fontScale($size) / Units::POINT,
        );

        return [[$mark], $shown];
    }

    /** @return array{list<Mark>, float} a sprite's image, standing on a line's foot, and its width */
    private function picture(PlantUmlSprite $sprite, float $scale, ?string $color, float $left, float $foot): array
    {
        [$width, $height] = [$sprite->width * $scale, $sprite->height * $scale];
        $tint = null === $color ? $this->color : PlantUmlColor::parse($color) ?? '#ffffff';
        $uri = $sprite->png('none' === $this->background ? '#ffffff' : $this->background, 'none' === $tint ? '#000000' : $tint);

        return [[new Mark(MarkKind::Image, [[$left, $foot - $height], [$left + $width, $foot]], $uri)], $width];
    }

    /** The face a line's own markup gives it, over the one it would have. */
    private static function face(string $line, FontFace $face): FontFace
    {
        $line = (string) \preg_replace('/<\/?(?:color|size|font|back)(?::[^>]*)?>/i', replacement: '', subject: $line);
        $bold = FontFace::Bold === $face || FontFace::BoldItalic === $face || 1 === \preg_match('/^\s*(?:\*\*.*\*\*|<b>.*<\/b>)\s*$/i', $line);
        $italic = FontFace::Italic === $face || FontFace::BoldItalic === $face || 1 === \preg_match('/^\s*(?:\/\/.*\/\/|<i>.*<\/i>)\s*$/i', $line);

        return match (true) {
            $bold && $italic => FontFace::BoldItalic,
            $bold => FontFace::Bold,
            $italic => FontFace::Italic,
            default => FontFace::Regular,
        };
    }
}
