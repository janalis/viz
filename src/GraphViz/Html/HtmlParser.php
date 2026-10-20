<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Html;

use Janalis\Viz\GraphViz\DotImage;
use Janalis\Viz\GraphViz\Drawing\DotColors;
use Janalis\Viz\GraphViz\Drawing\DotLabel;
use Janalis\Viz\GraphViz\Drawing\Justify;
use Janalis\Viz\Source\Unsupported;

/**
 * Reads an HTML label as DOT documents it: text, its spans in `<FONT>`, `<B>`, `<I>`, `<U>`,
 * `<O>`, `<S>`, `<SUB>` and `<SUP>`, its lines ended by `<BR/>`; or a `<TABLE>` of `<TR>` rows of
 * `<TD>` cells, ruled by `<HR/>` and `<VR/>`, a cell holding text, a table or an `<IMG/>` read
 * already. Whatever else it meets — an image not read, a malformed label, nesting past
 * {@see self::MAX_DEPTH} — it leaves to `dot`.
 */
final class HtmlParser
{
    /** The deepest elements nest: past it, the label is refused, so a hostile one cannot exhaust the stack. */
    public const int MAX_DEPTH = 32;
    /** The most rows or columns a cell may span. */
    public const int MAX_SPAN = 100;
    /** The most grid places a label's cells may cover together, their spans multiplied, so no label lays out an outsized grid. */
    public const int MAX_AREA = 10_000;
    private const array TEXT = ['b', 'i', 'u', 'o', 's', 'sub', 'sup', 'font'];
    private const string PATTERN = '/<!--.*?-->|<\/\s*([A-Za-z]+)\s*>|<([A-Za-z]+)((?:\s+[A-Za-z-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>|[^<]+|</s';

    /** @var list<array{string, string, array<string, string>}> each token's kind — `text`, `open`, `empty` or `close` — its element or text, and its attributes */
    private array $tokens = [];
    private int $at = 0;
    private int $area = 0;

    /** @param array<string, DotImage> $images the images `<IMG SRC>` may name, read already */
    private function __construct(
        string $source,
        private readonly array $images = [],
    ) {
        $matches = [];
        \preg_match_all(self::PATTERN, $source, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->tokens[] = self::token($match);
        }
        $this->tokens = \array_values(\array_filter($this->tokens, static fn (array $item): bool => 'comment' !== $item[0]));
    }

    /** @throws Unsupported when the label holds what the native drawing leaves to `dot` */
    /**
     * @param array<string, DotImage> $images the images `<IMG SRC>` may name, read already
     * @throws Unsupported when the label holds what the native drawing leaves to `dot`
     */
    public static function parse(string $source, HtmlFont $font, array $images = []): DotLabel
    {
        $parser = new self($source, $images);
        $label = $parser->label($font, closing: null, depth: 0);
        if ($parser->at < \count($parser->tokens)) {
            throw self::malformed();
        }

        return $label;
    }

    /** A table, maybe within text elements, or text: up to the element that closes it. */
    private function label(HtmlFont $font, ?string $closing, int $depth): DotLabel
    {
        $start = $this->at;
        $table = $this->fontTable($font, $depth);
        if (null === $table) {
            $this->at = $start;

            return $this->text($font, $closing, $depth);
        }
        $this->blank();

        return $table;
    }

    private function fontTable(HtmlFont $font, int $depth): ?HtmlTable
    {
        self::deep($depth);
        $this->blank();
        [$kind, $name, $attributes] = $this->tokens[$this->at] ?? ['', '', []];
        if ('open' === $kind && 'table' === $name) {
            return $this->table($font, $attributes, $depth);
        }
        if ('open' !== $kind || !\in_array($name, self::TEXT, strict: true)) {
            return null;
        }
        ++$this->at;
        $table = $this->fontTable($font->within($name, $attributes), $depth + 1);
        if (null === $table) {
            return null;
        }
        $this->blank();
        $this->close($name);

        return $table;
    }

    private function text(HtmlFont $font, ?string $closing, int $depth): HtmlText
    {
        $lines = [];
        $spans = [];
        $this->spans($font, $closing, $depth, $lines, $spans);
        $lines[] = [$spans, null, $font->size];

        return new HtmlText($lines);
    }

    /**
     * @param list<array{list<array{string, HtmlFont}>, ?Justify, float}> $lines
     * @param list<array{string, HtmlFont}> $spans the current line's
     */
    private function spans(HtmlFont $font, ?string $closing, int $depth, array &$lines, array &$spans): void
    {
        self::deep($depth);
        while ($this->at < \count($this->tokens)) {
            [$kind, $name, $attributes] = $this->tokens[$this->at++];
            if ('text' === $kind) {
                $spans[] = [$name, $font];
                continue;
            }
            if ('close' === $kind) {
                if ($name !== $closing) {
                    throw self::malformed();
                }

                return;
            }
            if ('br' === $name) {
                $lines[] = [$spans, self::justify($attributes['align'] ?? ''), $font->size];
                $spans = [];
                $this->skip('br');
                continue;
            }
            self::known($name, self::TEXT);
            $this->spans($font->within($name, $attributes), $name, $depth + 1, $lines, $spans);
        }
        if (null !== $closing) {
            throw self::malformed();
        }
    }

    /** @param array<string, string> $attributes */
    private function table(HtmlFont $font, array $attributes, int $depth): HtmlTable
    {
        ++$this->at;
        $border = self::number($attributes['border'] ?? '', 1.0);
        $rows = [];
        $ruledRows = [];
        $ruledCells = [];
        while (true) {
            $this->blank();
            [$kind, $name] = $this->tokens[$this->at] ?? ['close', ''];
            if ('close' === $kind) {
                $this->close('table');
                break;
            }
            self::known($name, ['tr', 'hr']);
            if ('hr' === $name) {
                $ruledRows[\count($rows) - 1] = true;
                ++$this->at;
                $this->skip('hr');
                continue;
            }
            ++$this->at;
            $rows[] = $this->row($font, $attributes, $border, $depth, $ruledCells[\count($rows)]);
        }

        return new HtmlTable(
            $rows,
            $attributes,
            $border,
            self::number($attributes['cellspacing'] ?? '', 2.0),
            DotColors::resolve($attributes['color'] ?? '', '#000000'),
            $ruledRows,
            \array_filter($ruledCells),
        );
    }

    /**
     * @param array<string, string> $table the table's attributes
     * @param ?array<int, true> $ruled the cells a `<VR/>` follows
     * @return list<HtmlCell>
     */
    private function row(HtmlFont $font, array $table, float $border, int $depth, ?array &$ruled): array
    {
        $cells = [];
        $ruled = [];
        while (true) {
            $this->blank();
            [$kind, $name, $attributes] = $this->tokens[$this->at] ?? ['close', '', []];
            if ('close' === $kind) {
                $this->close('tr');

                return $cells;
            }
            self::known($name, ['td', 'vr']);
            ++$this->at;
            if ('vr' === $name) {
                $ruled[\count($cells) - 1] = true;
                $this->skip('vr');
                continue;
            }
            $content = $this->image() ?? $this->label($font, 'td', $depth + 1);
            if (!$content instanceof HtmlText) {
                $this->close('td');
            }
            $rowspan = self::span($attributes['rowspan'] ?? '');
            $colspan = self::span($attributes['colspan'] ?? '');
            $this->area += $rowspan * $colspan;
            if ($this->area > self::MAX_AREA) {
                throw new Unsupported('The native DOT renderer does not draw HTML tables of more than '.self::MAX_AREA.' cells');
            }
            $cells[] = new HtmlCell(
                $content,
                $attributes,
                self::number($attributes['border'] ?? $table['cellborder'] ?? '', $border),
                self::number($attributes['cellpadding'] ?? $table['cellpadding'] ?? '', 2.0),
                $rowspan,
                $colspan,
            );
        }
    }

    /** A cell's `<IMG/>`, where it holds one and nothing more: one of the images read, else left to dot. */
    private function image(): ?HtmlImage
    {
        $start = $this->at;
        $this->blank();
        [$kind, $name, $attributes] = $this->tokens[$this->at] ?? ['', '', []];
        if ('img' !== $name || 'close' === $kind) {
            $this->at = $start;

            return null;
        }
        ++$this->at;
        $this->skip('img');
        $this->blank();
        $image = $this->images[$attributes['src'] ?? ''] ?? throw new Unsupported('The native DOT renderer does not draw images in HTML labels it may not read');

        return new HtmlImage($image);
    }

    /** Steps past text that is only white space. */
    private function blank(): void
    {
        while ('text' === ($this->tokens[$this->at][0] ?? '') && '' === \trim($this->tokens[$this->at][1], characters: " \t\n\r")) {
            ++$this->at;
        }
    }

    private function close(string $name): void
    {
        [$kind, $closed] = $this->tokens[$this->at] ?? ['', ''];
        if ('close' !== $kind || $closed !== $name) {
            throw self::malformed();
        }
        ++$this->at;
    }

    /** Steps past the closing tag an element written open, such as `<BR></BR>`, may have. */
    private function skip(string $name): void
    {
        [$kind, $closed] = $this->tokens[$this->at] ?? ['', ''];
        if ('close' === $kind && $closed === $name) {
            ++$this->at;
        }
    }

    /** @param list<string> $allowed */
    private static function known(string $name, array $allowed): void
    {
        if ('img' === $name) {
            throw new Unsupported('The native DOT renderer does not draw images in HTML labels');
        }
        if (!\in_array($name, $allowed, strict: true)) {
            throw self::malformed();
        }
    }

    private static function deep(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new Unsupported('The native DOT renderer does not draw HTML labels nested deeper than '.self::MAX_DEPTH);
        }
    }

    private static function malformed(): Unsupported
    {
        return new Unsupported('The native DOT renderer does not draw this HTML label');
    }

    private static function justify(string $align): ?Justify
    {
        return match (\strtolower($align)) {
            'left' => Justify::Left,
            'right' => Justify::Right,
            'center' => Justify::Center,
            default => null,
        };
    }

    private static function number(string $value, float $default): float
    {
        return \is_numeric($value) ? \max(0.0, \min(255.0, (float) $value)) : $default;
    }

    private static function span(string $value): int
    {
        return \is_numeric($value) ? \max(1, \min(self::MAX_SPAN, (int) $value)) : 1;
    }

    /**
     * @param array<array-key, string> $match
     * @return array{string, string, array<string, string>}
     */
    private static function token(array $match): array
    {
        $whole = $match[0];
        if (\str_starts_with($whole, '<!--')) {
            return ['comment', '', []];
        }
        if ('' !== ($match[1] ?? '')) {
            return ['close', \strtolower($match[1]), []];
        }
        if ('' !== ($match[2] ?? '')) {
            return [
                '/' === ($match[4] ?? '') || 'br' === \strtolower($match[2]) || \in_array(\strtolower($match[2]), ['hr', 'vr'], strict: true) ? 'empty' : 'open',
                \strtolower($match[2]),
                self::attributes($match[3] ?? ''),
            ];
        }
        if ('<' === $whole) {
            throw self::malformed();
        }

        return ['text', \preg_replace('/\s+/', replacement: ' ', subject: \html_entity_decode($whole, \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8')) ?? '', []];
    }

    /** @return array<string, string> */
    private static function attributes(string $source): array
    {
        $matches = [];
        \preg_match_all('/([A-Za-z-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $source, $matches, \PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[\strtolower($match[1])] = \html_entity_decode(($match[2] ?? '').($match[3] ?? ''), \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8');
        }

        return $attributes;
    }
}
