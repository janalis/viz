<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;

/**
 * Lines of Creole read into a sheet as PlantUML's legacy `CreoleParser` reads them: `|`
 * lines a table and `|_` lines a tree, each going on while the next line is one too; lines
 * between `<code>` and `</code>` as written; a line of `*` a bulleted item, of `#` a numbered
 * one — numbers counted at each depth across the whole sheet — of `=` a heading; any other
 * line text, set as the line above it unless it says otherwise. A `@startcreole` block is
 * all lines of Creole in a 14-point serif, black.
 */
final class CreoleReader
{
    private const string TABLE = '/^(<#\w+(,#?\w+)?>)?\|(=)?.*\|$/u';

    private CreoleSheet $sheet;
    /** @var list<int> how many items each depth of numbered list has had */
    private array $numbers = [];

    private function __construct(
        private readonly CreoleFont $font,
    ) {
        $this->sheet = new CreoleSheet();
    }

    /** @throws Unsupported on what the native renderer leaves to PlantUML, or what PlantUML fails on */
    public static function read(PlantUmlSource $source): PlantUmlCreole
    {
        return new PlantUmlCreole(new PlantUmlDocument([], StyleSheet::skin(), pragmas: $source->pragmas, kind: 'creole'), $source->lines, self::sheet($source->lines, new CreoleFont()));
    }

    /**
     * @param list<string> $lines
     * @throws Unsupported on what the native renderer leaves to PlantUML, or what PlantUML fails on
     */
    public static function sheet(array $lines, CreoleFont $font): CreoleSheet
    {
        $reader = new self($font);
        foreach ($lines as $line) {
            $reader->line((string) \preg_replace('/<<\s?((?:<&\w+>|[^<>])+?)\s?>>/u', replacement: '«$1»', subject: $line));
        }

        return $reader->sheet;
    }

    private function line(string $line): void
    {
        $last = $this->sheet->last();
        $part = $last instanceof CreoleMargin ? $last->atom : $last;
        if (\str_starts_with(\trim($line), '{{')) {
            throw new Unsupported('The native PlantUML renderer leaves diagrams within Creole to PlantUML');
        }
        if ($part instanceof CreoleCode && !$part->ended) {
            '</code>' === $line ? ($part->ended = true) : ($part->lines[] = $line);

            return;
        }
        $table = 1 === \preg_match(self::TABLE, $line);
        if ($part instanceof CreoleTable && $table) {
            $this->table($part, $line);

            return;
        }
        if ($part instanceof CreoleTree && \str_starts_with(\trim($line), '|_')) {
            $this->tree($part, $line);

            return;
        }
        match (true) {
            $table => $this->sheet->add(new CreoleMargin($this->table(new CreoleTable(self::colors($line)[1] ?? $this->font->color), $line))),
            \str_starts_with($line, '|_') => $this->sheet->add(new CreoleMargin($this->tree(new CreoleTree($this->font->color), $line))),
            '<code>' === $line => $this->sheet->add(new CreoleCode($this->font->with(['family' => 'monospaced']))),
            '<latex>' === $line => throw new Unsupported('The native PlantUML renderer leaves <latex> to PlantUML'),
            default => $this->sheet->add($this->stripe($line, $last instanceof CreoleStripe ? $last->align : 'left')),
        };
    }

    /** As `CreoleStripeSimpleParser`: what kind of line it is, from how it starts. */
    private function stripe(string $line, string $align): CreoleStripe
    {
        if (1 === \preg_match('/^(?:--[^-]*--|==[^=]*==|===*==|\.\.[^.]*\.\.)$/u', $line)) {
            throw new Unsupported('PlantUML fails on a horizontal line in a Creole diagram');
        }
        $match = [];
        if (1 === \preg_match('/^(\*+)([^*]+(?:[^*]|\*\*[^*]+\*\*)*)$/u', $line, $match) || 1 === \preg_match('/^(\*+)(\s.+)$/u', $line, $match)) {
            return $this->filled(new CreoleStripe($this->font, 'bullet', \strlen($match[1]) - 1, $align, new CreoleBullet(\strlen($match[1]) - 1, $this->font->color)), \trim($match[2]));
        }
        if (1 === \preg_match('/^(#+)(.+)$/u', CreoleStripe::hide($line), $match)) {
            $order = \strlen($match[1]) - 1;
            $number = new CreoleWord($this->number($order).'.', $this->font, $this->font->width('9. ') * $order, $this->font->width('.'));

            return $this->filled(new CreoleStripe($this->font, 'number', $order, $align, $number), \trim(CreoleStripe::unhide($match[2])));
        }
        if (1 === \preg_match('/^(=+)(.+)$/u', $line, $match)) {
            return $this->filled(new CreoleStripe($this->font, 'heading', \strlen($match[1]) - 1, $align), \trim($match[2]));
        }

        return $this->filled(new CreoleStripe($this->font, 'normal', 0, $align), $line);
    }

    private function filled(CreoleStripe $stripe, string $text): CreoleStripe
    {
        $stripe->add($text);

        return $stripe;
    }

    /** As `CreoleContext`'s `getLocalNumber`: the next number at a depth, those deeper forgotten. */
    private function number(int $order): int
    {
        $this->numbers = \array_slice(\array_pad($this->numbers, length: $order + 1, value: 0), offset: 0, length: $order + 1);

        return ++$this->numbers[$order];
    }

    /** As `StripeTable`'s `analyzeAndAddInternal`: a row on its colour, each cell between bars on its own, `=` a bold header. */
    private function table(CreoleTable $table, string $line): CreoleTable
    {
        $line = \str_replace('\|', replace: "\u{E000}", subject: $line);
        $colors = self::colors($line);
        $table->row($colors[0] ?? null);
        $line = [] === $colors ? $line : \substr($line, (int) \strpos($line, needle: '>') + 1);
        foreach (\explode(separator: '|', string: $line) as $cell) {
            if ('' === $cell) {
                continue;
            }
            $cell = \str_replace("\u{E000}", replace: '|', subject: $cell);
            $header = \str_starts_with($cell, '=');
            $cell = $header ? \substr($cell, offset: 1) : $cell;
            $back = self::colors($cell);
            $cell = [] === $back ? $cell : \substr($cell, (int) \strpos($cell, needle: '>') + 1);
            $sheet = new CreoleSheet();
            foreach (self::lines($cell) as $text) {
                $right = \str_starts_with($text, '<r>');
                $stripe = new CreoleStripe($header ? $this->font->with(['bold' => true]) : $this->font, 'normal', 0, $right ? 'right' : 'left');
                $stripe->add($right ? \substr($text, offset: 3) : $text);
                $sheet->add($stripe);
            }
            $table->cell($sheet, $back[0] ?? null);
        }

        return $table;
    }

    /** As `StripeTree`: each line a level deeper for every two spaces or tab before its `|_`. */
    private function tree(CreoleTree $tree, string $line): CreoleTree
    {
        foreach (self::lines($line) as $text) {
            $match = [];
            \preg_match('/^((?:  |\t)*)/', $text, $match);
            $level = 1 + \strlen(\str_replace('  ', replace: "\t", subject: $match[1] ?? ''));
            $stripe = new CreoleStripe($this->font, 'normal', 0, 'left');
            $stripe->add((string) \preg_replace('/^\s*\|_/u', replacement: '', subject: $text, limit: 1));
            $sheet = new CreoleSheet();
            $sheet->add($stripe);
            $tree->cell($sheet, $level);
        }

        return $tree;
    }

    /** @return list<string> as `getWithNewlinesInternal`: a cell's lines, split at `\n` */
    private static function lines(string $text): array
    {
        return \explode(
            "\n",
            (string) \preg_replace_callback(
                '/\\\\(.)/u',
                static fn (array $match): string => match ($match[1]) {
                    'n' => "\n",
                    '\\' => '\\',
                    default => $match[0],
                },
                $text,
            ),
        );
    }

    /** @return list<string> the colours `<#…>` or `<#…,#…>` at its start gives, as PlantUML reads them, white for one it does not know */
    private static function colors(string $text): array
    {
        $match = [];
        if (1 !== \preg_match('/^=?\s*<(#\w+(?:,#?\w+)?)>/u', $text, $match)) {
            return [];
        }

        return \array_map(static fn (string $color): string => PlantUmlColor::parse($color) ?? '#ffffff', \explode(',', $match[1]));
    }
}
