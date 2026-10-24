<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\FontFace;

/**
 * PlantUML text in points, measured as PlantUML measures its font, each character drawn in the
 * middle of its own room: lines of Creole with their markup taken out —
 * a line wholly bold or italic drawn so, a heading — one to three = before it — bigger and bold,
 * a sprite (`<$name>`) drawn in its place as PlantUML draws one — measured, and set as text
 * marks left, centred or right within a width.
 */
final readonly class PlantUmlText
{
    /**
     * A line's height for each point of font size, as Java measures PlantUML's default font,
     * its SansSerif: 1.1777 times its size, as PlantUML's goldens set it.
     */
    private const float LINE = 603 / 512;
    /** How far below its baseline that font reaches for each point of size. */
    private const float DESCENT = 0.210_937_5;
    /** The least height of a line, as PlantUML sets small text: the room below the text. */
    private const float SHORTEST = 10.0;

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
        foreach (self::stretches($text, $size) as [$part, $at]) {
            $width += PlantUmlMetrics::width($part, $at, $face);
        }

        return $width;
    }

    /**
     * A stretch of Creole in the pieces its `<size:…>` sets apart, each without its markup and
     * at its size — a piece of a line may hold markup it does not close, as wrapping cuts it:
     * every marker comes out.
     *
     * @return list<array{string, float}>
     */
    private static function stretches(string $text, float $size): array
    {
        $stretches = [];
        $sizes = [$size];
        foreach ((array) \preg_split('/(<\/?size(?::[^>]*)?>)/i', $text, flags: \PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            $part = (string) $part;
            if (0 === ($index % 2)) {
                $stretches[] = [\str_replace(['**', '//', '""', '__', '~~'], replace: '', subject: self::plain($part)), $sizes[\count($sizes) - 1]];
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

        return $stretches;
    }

    /** The size of a stretch's tallest text, as PlantUML sets a line on it; its own size when it has none. */
    private static function tallest(string $text, float $size): float
    {
        $sizes = self::sizes($text, $size);

        return [] === $sizes ? $size : \max($sizes);
    }

    /** The size a stretch is drawn at: the one all its text shares, else its own. */
    private static function drawn(string $text, float $size): float
    {
        $sizes = \array_values(\array_unique(self::sizes($text, $size)));

        return 1 === \count($sizes) ? $sizes[0] : $size;
    }

    /** @return list<float> the size of each piece of a stretch with text in it */
    private static function sizes(string $text, float $size): array
    {
        $sizes = [];
        foreach (self::stretches($text, $size) as [$part, $at]) {
            if ('' === \trim($part)) {
                continue;
            }
            $sizes[] = $at;
        }

        return $sizes;
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
            [$blockWidth, $blockHeight] = $block instanceof PlantUmlTable ? $block->size() : $this->lineSize($this->atoms($block), $this->fontSize($block), $block);
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
            // As PlantUML's Creole sets them, each stretch of bold or italic markup in its own face.
            foreach ((array) \preg_split(
                '/(\*\*.+?\*\*|<b>.+?<\/b>|\/\/.+?\/\/|<i>.+?<\/i>|<&[\w-]+>)/iu',
                (string) $parts[$at],
                flags: \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY,
            ) as $stretch) {
                $atoms[] = [(string) $stretch, null, 0.0, null];
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
    private function lineSize(array $atoms, float $size, string $line): array
    {
        $width = 0.0;
        $height = self::lineHeight($this->tallestOf($atoms, $size));
        foreach ($atoms as [$text, $sprite, $scale]) {
            $width += null === $sprite ? self::shown($text, $size, $this->faceOf($line, $text)) : $sprite->width * $scale;
            $height = \max($height, null === $sprite ? 0.0 : $sprite->height * $scale);
        }

        return [$width, $height];
    }

    public function line(): float
    {
        return self::lineHeight($this->size);
    }

    /** How far below the top of its first line the text's baseline stands, as Java measures the font. */
    public function ascent(): float
    {
        return self::lineHeight($this->size) - self::below($this->size);
    }

    /**
     * How far past its block PlantUML's limit finder takes the text: from 1.5 below its first
     * line's top less what that line keeps below its baseline, to 1.5 below its last line's
     * baseline; a table as its box.
     *
     * @return array{float, float} how far above its top, and how far short of its bottom
     */
    public function overhang(): array
    {
        $past = [];
        foreach ($this->blocks() as $line) {
            $past[] = $line instanceof PlantUmlTable ? 0.0 : self::below($this->tallestOf($this->atoms($line), $this->fontSize($line))) - 1.5;
        }

        return [$past[0] ?? 0.0, $past[\count($past) - 1] ?? 0.0];
    }

    /** A line's height at a font size in points, as PlantUML measures its default font: 10 at least. */
    public static function lineHeight(float $size): float
    {
        return \max(self::SHORTEST, $size * self::LINE);
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
            [$shown, $height] = $this->lineSize($atoms, $size, $line);
            $foot = $top + $height;
            $top = $foot;
            $left = match ($align) {
                'left' => $x,
                'right' => $x + $width - $shown,
                default => $x + (($width - $shown) / 2),
            };
            $tallest = $this->tallestOf($atoms, $size);
            foreach ($atoms as [$text, $sprite, $scale, $color]) {
                [$mark, $advance] = null === $sprite ? $this->word($line, $text, $size, $left, $foot - self::below($tallest)) : $this->picture($sprite, $scale, $color, $left, $foot);
                \array_push($marks, ...$mark);
                $left += $advance;
            }
        }

        return $marks;
    }

    /**
     * @param list<array{string, ?PlantUmlSprite, float, ?string}> $atoms
     */
    private function tallestOf(array $atoms, float $size): float
    {
        $tallest = null;
        foreach ($atoms as [$text, $sprite]) {
            if (null !== $sprite) {
                continue;
            }
            $tallest = \max($tallest ?? 0.0, self::tallest($text, $size));
        }

        return $tallest ?? $size;
    }

    /** How far below its baseline a line of text at a size reaches, as tall as Java measures it: the font's descent, and the room a short line keeps. */
    private static function below(float $size): float
    {
        return self::lineHeight($size) - ((self::LINE - self::DESCENT) * $size);
    }

    /** As wide as the bundled font draws a stretch, each piece at its size. */
    private static function shown(string $text, float $size, FontFace $face): float
    {
        // An OpenIconic icon, not drawn, takes the room PlantUML gives it: two thirds of the size, and 2, as measured on its output.
        if (1 === \preg_match('/^<&[\w-]+>$/', $text)) {
            return ((2 * $size) / 3) + 2;
        }
        if (!\str_contains(\strtolower($text), '<size')) {
            return PlantUmlMetrics::width(self::plain($text), $size, $face);
        }
        $width = 0.0;
        foreach (self::stretches($text, $size) as [$part, $at]) {
            $width += PlantUmlMetrics::width($part, $at, $face);
        }

        return $width;
    }

    /** @return array{list<Mark>, float} a stretch of text's mark, standing on its baseline, and its width */
    private function word(string $line, string $text, float $size, float $left, float $baseline): array
    {
        $plain = self::plain($text);
        $face = $this->faceOf($line, $text);
        $shown = self::shown($text, $size, $face);
        $size = self::drawn($text, $size);
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
            [[$left + ($shown / 2), $baseline]],
            $plain,
            stroke: $color,
            font: $face,
            fontScale: Units::fontScale($size) / Units::POINT,
            slots: self::slots($plain, $size, $face, $shown),
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

    /**
     * Where each character stands, as PlantUML sets it: in the room its advance takes in
     * PlantUML's font, all of them as wide as the stretch is shown.
     *
     * @return list<float>
     */
    private static function slots(string $plain, float $size, FontFace $face, float $shown): array
    {
        $slots = PlantUmlMetrics::slots($plain, $size, $face);
        $sum = \array_sum($slots);

        return $sum > 0 ? \array_map(static fn (float $slot): float => ($slot * $shown) / $sum, $slots) : $slots;
    }

    /** The face a stretch of a line takes: its heading's, or its line's own markup over the text's. */
    private function faceOf(string $line, string $text): FontFace
    {
        $depth = self::heading($line)[0];

        return self::face(match (true) {
            $depth < 0 => $text,
            $depth < 3 => '**'.$text.'**',
            default => '//'.$text.'//',
        }, $this->face);
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
