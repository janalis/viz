<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A salt diagram's tokens read into widgets as PlantUML's element factories read them: the
 * whole a grid, a scrolled grid, a border or a tree; in a block, each token tried as a menu,
 * a tree, tabs, a rule, a text field, a check box, a button, a drop list, a radio button, a
 * picture, a picture named before, a text, then a block, in that order.
 */
final class SaltParser
{
    private const array STRATEGIES = [' ', '+', '^', '-', '!', '#'];

    /** @var array<string, SaltImage> */
    private array $pictures = [];
    /** @var list<string> each widget's text, in the order read */
    public array $texts = [];

    public function __construct(
        private readonly SaltTokens $tokens,
    ) {}

    /** The widget the tokens make, as PlantUML's `createElement`. */
    public function root(): SaltElement
    {
        $root = $this->block();

        return $root[0] ?? throw new PlantUmlRefusal('A salt diagram starts with a block');
    }

    /** @return ?array{SaltElement, bool} a block at the next token, and whether it ends its line */
    private function block(): ?array
    {
        return $this->grid() ?? $this->scroll() ?? $this->border() ?? $this->tree();
    }

    /** @return array{SaltElement, bool} */
    private function element(): array
    {
        $word = $this->tokens->peek();
        $simple = $this->menu() ?? $this->tree() ?? $this->tabs();
        if (null !== $simple) {
            return $simple;
        }
        if (self::rule($word)) {
            [$text, $ends] = $this->tokens->next();

            return [new SaltLine($text[0]), $ends];
        }
        $widget = $this->widget($word);
        if (null !== $widget) {
            $ends = $this->tokens->next()[1];
            $this->texts[] = $word;

            return [$widget, $ends];
        }
        if (null !== ($picture = $this->picture())) {
            return $picture;
        }
        if (1 === \preg_match('/^<<\w+>>$/', $word)) {
            $ends = $this->tokens->next()[1];
            $name = \substr($word, offset: 2, length: -2);

            return [$this->pictures[$name] ?? throw new PlantUmlRefusal('No picture '.$name), $ends];
        }
        if (!\str_starts_with($word, '{') && !\str_starts_with($word, '}')) {
            $ends = $this->tokens->next()[1];
            $this->texts[] = $word;

            return [new SaltText($word), $ends];
        }

        return $this->block() ?? throw new PlantUmlRefusal('No salt widget reads '.$word);
    }

    /** A widget of one token: a text field, a check box, a button, a drop list or a radio button. */
    private function widget(string $word): ?SaltElement
    {
        // As PlantUML, which fails to take the inside of a lone quote or caret.
        if ('"' === $word || '^' === $word) {
            throw new PlantUmlRefusal('A salt widget with nothing inside '.$word);
        }
        $inner = \substr($word, offset: 1, length: -1);

        return match (true) {
            \str_starts_with($word, '"') && \str_ends_with($word, '"') && \strlen($word) > 1 => new SaltTextField($inner),
            \str_starts_with($word, '[X]') => new SaltChoice(self::after($word, ']'), radio: false, checked: true),
            \str_starts_with($word, '[]') || \str_starts_with($word, '[ ]') => new SaltChoice(self::after($word, ']'), radio: false, checked: false),
            !\str_starts_with($word, '[[') && \str_starts_with($word, '[') && \str_ends_with($word, ']') => new SaltButton($inner),
            \str_starts_with($word, '^') && \str_ends_with($word, '^') && \strlen($word) > 1 => new SaltDroplist($inner),
            \str_starts_with($word, '(X)') => new SaltChoice(self::after($word, ')'), radio: true, checked: true),
            \str_starts_with($word, '()') || \str_starts_with($word, '( )') => new SaltChoice(self::after($word, ')'), radio: true, checked: false),
            default => null,
        };
    }

    /** @return ?array{SaltElement, bool} `{* entries`, then a line for each opened entry: its text, then its items */
    private function menu(): ?array
    {
        if ('{*' !== $this->tokens->peek()) {
            return null;
        }
        $this->tokens->next();
        $menu = new SaltMenu();
        $entry = null;
        while ('}' !== $this->tokens->peek()) {
            [$text, $ends] = $this->tokens->next();
            match (true) {
                null === $entry => $menu->entry($text),
                '' === $entry => $entry = $text,
                default => $menu->item($entry, $text),
            };
            $this->texts[] = $text;
            if ($ends) {
                $entry = '';
            }
        }

        return [$menu, $this->tokens->next()[1]];
    }

    /** @return ?array{SaltElement, bool} `{/ tabs`, one under another when one ends its line */
    private function tabs(): ?array
    {
        if ('{/' !== $this->tokens->peek()) {
            return null;
        }
        $this->tokens->next();
        $tabs = new SaltTabs();
        while ('}' !== $this->tokens->peek()) {
            [$text, $ends] = $this->tokens->next();
            $tabs->tab($text);
            $this->texts[] = $text;
            $tabs->vertical = $tabs->vertical || $ends;
        }

        return [$tabs, $this->tokens->next()[1]];
    }

    /** @return ?array{SaltElement, bool} `{T` and an entry a line, its depth in `+`, its other cells after it */
    private function tree(): ?array
    {
        if ('{' !== $this->tokens->peek() || !self::treeMarker($this->tokens->peek(1))) {
            return null;
        }
        $this->tokens->next();
        $marker = $this->tokens->next()[0];
        $tree = new SaltTree(2 === \strlen($marker) ? $marker[1] : ' ');
        $first = true;
        $depth = 0;
        while ($depth > 0 || '}' !== $this->tokens->peek()) {
            if ($first) {
                [$text, $ends] = $this->tokens->next();
                $depth += match (true) {
                    '}' === $text => -1,
                    \str_starts_with($text, '{') => 1,
                    default => 0,
                };
                $tree->entry($text);
                $this->texts[] = \ltrim($text, characters: '+ ');
                $first = $ends;
                continue;
            }
            [$cell, $ends] = $this->element();
            $tree->cell($cell);
            $first = $ends;
        }

        return [$tree, $this->tokens->next()[1]];
    }

    /** @return ?array{SaltElement, bool} a grid, `{`, `{+`, `{^"title"`, `{-`, `{!` or `{#`, a `*` widening the widget before it */
    private function grid(): ?array
    {
        $word = $this->tokens->peek();
        if (!\in_array($word, ['{', '{+', '{^', '{#', '{!', '{-'], strict: true) || self::side($this->tokens->peek(1)) || self::treeMarker($this->tokens->peek(1))) {
            return null;
        }
        [$header, $ends] = $this->tokens->next();
        $strategy = 2 === \strlen($header) ? $header[1] : ' ';
        $title = null;
        if ('^' === $strategy && !$ends) {
            $title = $this->tokens->next()[0];
            if (\strlen($title) > 1 && \str_starts_with($title, '"') && \str_ends_with($title, '"')) {
                $title = \substr($title, offset: 1, length: -1);
            }
            $this->texts[] = $title;
        }
        $positions = new SaltPositions();
        while ('}' !== $this->tokens->peek()) {
            [$element, $elementEnds] = $this->element();
            if ($element instanceof SaltText && '*' === $element->text) {
                \array_pop($this->texts);
                $positions->widen([$element, $elementEnds]);
                continue;
            }
            $positions->add([$element, $elementEnds]);
        }

        return [$positions->grid($strategy, $title), $this->tokens->next()[1]];
    }

    /** @return ?array{SaltElement, bool} `{S`, `{SI` or `{S-`: a grid ruled around, with scroll bars */
    private function scroll(): ?array
    {
        $word = $this->tokens->peek();
        if (!\in_array($word, ['{S', '{S-', '{SI'], strict: true)) {
            return null;
        }
        $this->tokens->next();
        $positions = new SaltPositions();
        while ('}' !== $this->tokens->peek()) {
            $positions->add($this->element());
        }

        return [new SaltScroll($positions->grid('+'), !\str_ends_with($word, '-'), !\str_ends_with($word, 'I')), $this->tokens->next()[1]];
    }

    /** @return ?array{SaltElement, bool} a block whose first token is `N=`, `S=`, `E=` or `W=`: each such side, then its widget */
    private function border(): ?array
    {
        if (!\in_array($this->tokens->peek(), ['{', '{+', '{#', '{!', '{-'], strict: true) || !self::side($this->tokens->peek(1))) {
            return null;
        }
        $this->tokens->next();
        $border = new SaltBorder();
        while ('}' !== $this->tokens->peek()) {
            $side = $this->tokens->next()[0][0];
            $element = $this->element()[0];
            match ($side) {
                'N' => $border->north = $element,
                'S' => $border->south = $element,
                'E' => $border->east = $element,
                'W' => $border->west = $element,
                'C' => $border->center = $element,
                default => throw new PlantUmlRefusal('No side '.$side.' to a salt border'),
            };
        }

        return [$border, $this->tokens->next()[1]];
    }

    /** @return ?array{SaltElement, bool} `<<name` or `<<`, rows of `X` and `.`, then `>>`; kept by its name */
    private function picture(): ?array
    {
        $header = $this->tokens->peek();
        if ('<<' !== $header && 1 !== \preg_match('/^<<\w+$/', $header)) {
            return null;
        }
        $this->tokens->next();
        $rows = [];
        while ('>>' !== $this->tokens->peek()) {
            $rows[] = $this->tokens->next()[0];
        }
        $ends = $this->tokens->next()[1];
        if (1 !== \count(\array_unique(\array_map(\strlen(...), $rows)))) {
            throw new PlantUmlRefusal('A salt picture with rows of other lengths');
        }
        $picture = new SaltImage([$rows[0], ...\array_slice($rows, offset: 1)]);
        if (\strlen($header) > 2) {
            $this->pictures[\substr($header, offset: 2)] = $picture;
        }

        return [$picture, $ends];
    }

    private static function rule(string $word): bool
    {
        foreach (['--', '==', '~~', '..'] as $rule) {
            if (\str_starts_with($word, $rule) && \str_ends_with($word, $rule)) {
                return true;
            }
        }

        return false;
    }

    private static function side(string $word): bool
    {
        return 1 === \preg_match('/^[NSEW]=$/', $word);
    }

    private static function treeMarker(string $word): bool
    {
        return 'T' === $word || (2 === \strlen($word) && 'T' === $word[0] && \in_array($word[1], self::STRATEGIES, strict: true));
    }

    /** The text after a check box's or radio button's mark, trimmed. */
    private static function after(string $word, string $mark): string
    {
        return \trim(\substr($word, (int) \strpos($word, needle: $mark) + 1));
    }
}
