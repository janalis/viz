<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A regex read and built into a railroad as PlantUML's `PSystemRegex` builds one: its tokens
 * as its `RegexExpression` reads them — letters, escapes, classes, `[…]` groups, anchors,
 * quantifiers, `(?=`, `(?<=`, `(?<name>` and plain groups, `|` — joined where they follow
 * each other, put in postfix order as its `ShuntingYard` puts them, then built on a stack of
 * tiles, letters running together in one box. The stack carries over from line to line.
 */
final class RegexParser
{
    /** @var list<EbnfTile> its top first */
    private array $stack = [];
    /** @var list<array{string, string}> each token's kind and text */
    private array $tokens = [];
    /** @var list<string> */
    private array $characters = [];
    private int $at = 0;

    public function __construct(
        private readonly EbnfStyle $style,
    ) {}

    /** The tile on top of the stack, which PlantUML draws; null when there is none. */
    public function top(): ?EbnfTile
    {
        return $this->stack[0] ?? null;
    }

    /** @throws PlantUmlRefusal where PlantUML fails on the line */
    public function line(string $line): void
    {
        $this->characters = \mb_str_split($line);
        $this->at = 0;
        $this->tokens = [];
        while ('' !== $this->peek(0)) {
            $this->token();
        }
        $joined = [];
        foreach ($this->tokens as $token) {
            if ([] !== $joined && self::joins($joined[\count($joined) - 1][0], $token[0])) {
                $joined[] = ['CONCATENATION_IMPLICIT', ''];
            }
            $joined[] = $token;
        }
        foreach (self::postfix($joined) as [$kind, $data]) {
            $this->build($kind, $data);
        }
    }

    private function token(): void
    {
        $current = $this->peek(0);
        $next = $this->peek(1);
        [$kind, $data] = match (true) {
            '^' === $current || '$' === $current => ['ANCHOR', $this->take(1)],
            '\\' === $current && \str_contains('AZzGbB', $next) && '' !== $next => ['ANCHOR', $this->take(2)],
            '\\' === $current && \str_contains('.*\\?^$|()[]{}<>', $next) && '' !== $next => ['ESCAPED_CHAR', \substr($this->take(2), offset: 1)],
            '|' === $current => ['ALTERNATIVE', $this->take(1)],
            '[' === $current && '[' === $next && ':' === $this->peek(2) => ['CLASS', $this->posix()],
            '[' === $current => ['GROUP', $this->group()],
            '(' === $current && '?' === $next && '#' === $this->peek(2) => ['COMMENT', $this->comment()],
            '(' === $current && '?' === $next && ('=' === $this->peek(2) || '!' === $this->peek(2)) => ['LOOK_AHEAD', '?'.\substr($this->take(3), offset: 2)],
            '(' === $current && '?' === $next && '<' === $this->peek(2) && ('=' === $this->peek(3) || '!' === $this->peek(3)) => ['LOOK_BEHIND', '?<'.\substr($this->take(4), offset: 3)],
            $this->namedGroupStarts() => ['NAMED_GROUP', $this->namedGroup()],
            '(' === $current => ['PARENTHESIS_OPEN', $this->open()],
            ')' === $current => ['PARENTHESIS_CLOSE', $this->take(1)],
            \str_contains('*+?{', $current) => ['QUANTIFIER', $this->quantifier()],
            '\\' === $current && '0' === $next => ['CLASS', $this->escape(4)],
            '\\' === $current && 'u' === $next => ['CLASS', $this->escape(5)],
            '\\' === $current && ('p' === $next || 'x' === $next) && '{' === $this->peek(2) => ['CLASS', $this->unicodeClass()],
            '.' === $current => ['CLASS', $this->take(1)],
            '\\' === $current => ['CLASS', $this->take(2)],
            default => ['SIMPLE_CHAR', $this->take(1)],
        };
        if ('COMMENT' === $kind) {
            return;
        }
        $this->tokens[] = [$kind, $data];
        if (\in_array($kind, ['LOOK_AHEAD', 'LOOK_BEHIND', 'NAMED_GROUP'], strict: true)) {
            $this->tokens[] = ['PARENTHESIS_OPEN', '('];
        }
    }

    /** As PlantUML's `needImplicitConcatenation`: between two tokens but after `(`, around `|`, before a quantifier or `)`, after a group's start. */
    private static function joins(string $first, string $second): bool
    {
        return (
            !\in_array($first, ['NAMED_GROUP', 'LOOK_AHEAD', 'LOOK_BEHIND', 'ALTERNATIVE', 'PARENTHESIS_OPEN'], strict: true)
            && !\in_array($second, ['ALTERNATIVE', 'QUANTIFIER', 'PARENTHESIS_CLOSE'], strict: true)
        );
    }

    /**
     * @param list<array{string, string}> $tokens
     * @return list<array{string, string}>
     */
    private static function postfix(array $tokens): array
    {
        $output = [];
        /** @var list<array{string, string}> $operators the stack, its top first */
        $operators = [];
        foreach ($tokens as $token) {
            [$kind] = $token;
            if (\in_array($kind, ['NAMED_GROUP', 'LOOK_AHEAD', 'LOOK_BEHIND', 'CONCATENATION_IMPLICIT', 'PARENTHESIS_OPEN'], strict: true)) {
                $operators = [$token, ...$operators];
                continue;
            }
            if ('ALTERNATIVE' === $kind) {
                while ('CONCATENATION_IMPLICIT' === ($operators[0][0] ?? null)) {
                    $output[] = $operators[0];
                    $operators = \array_slice($operators, offset: 1);
                }
                $operators = [$token, ...$operators];
                continue;
            }
            if ('PARENTHESIS_CLOSE' !== $kind) {
                $output[] = $token;
                continue;
            }
            while ([] !== $operators && 'PARENTHESIS_OPEN' !== $operators[0][0]) {
                $output[] = $operators[0];
                $operators = \array_slice($operators, offset: 1);
            }
            if ([] === $operators) {
                throw new PlantUmlRefusal('Error parsing: unbalanced )');
            }
            $operators = \array_slice($operators, offset: 1);
            if (\in_array($operators[0][0] ?? null, ['NAMED_GROUP', 'LOOK_AHEAD', 'LOOK_BEHIND'], strict: true)) {
                $output[] = $operators[0];
                $operators = \array_slice($operators, offset: 1);
            }
        }

        return [...$output, ...$operators];
    }

    private function build(string $kind, string $data): void
    {
        match ($kind) {
            'SIMPLE_CHAR', 'ESCAPED_CHAR' => \array_unshift($this->stack, new BoxTile($data, EbnfSymbol::Terminal, $this->style)),
            'CLASS', 'ANCHOR' => \array_unshift($this->stack, new BoxTile($data, EbnfSymbol::Literal, $this->style)),
            'GROUP' => \array_unshift($this->stack, new RegexGroupTile(self::split($data), $this->style)),
            'LOOK_AHEAD', 'LOOK_BEHIND' => \array_unshift($this->stack, new LookAroundTile($this->pop(), $this->style, $data)),
            'NAMED_GROUP' => \array_unshift($this->stack, new NamedGroupTile($this->pop(), $this->style, $data)),
            'CONCATENATION_IMPLICIT' => $this->concatenation(),
            'ALTERNATIVE' => $this->alternation(),
            'QUANTIFIER' => $this->quantified($data),
            default => throw new PlantUmlRefusal('Error parsing: '.$kind.'['.$data.']'),
        };
    }

    private function quantified(string $quantifier): void
    {
        $first = $quantifier[0];
        if ('{' !== $first) {
            $tile = $this->pop();
            \array_unshift($this->stack, match ($first) {
                '*' => new OptionalTile(new OneOrMoreTile($tile, $this->style), $this->style),
                '+' => new OneOrMoreTile($tile, $this->style),
                default => new OptionalTile($tile, $this->style),
            });

            return;
        }
        $inside = \trim(\rtrim($quantifier, characters: '?'), characters: '{}');
        $comma = \strpos($inside, needle: ',');
        $written = \trim(false === $comma ? $inside : \substr($inside, offset: 0, length: $comma));
        $min = '' === $written ? 0 : (int) $written;
        $maximum = false === $comma ? $written : \trim(\substr($inside, $comma + 1));
        $max = '' === $maximum ? -1 : (int) $maximum;
        $tile = $this->pop();
        \array_unshift($this->stack, match (true) {
            0 === $min && $max <= 0 => new OptionalTile(new OneOrMoreTile($tile, $this->style), $this->style),
            0 === $min && 1 === $max => new OptionalTile($tile, $this->style),
            0 === $min => new OptionalTile(new OneOrMoreTile($tile, $this->style, $quantifier), $this->style),
            default => new OneOrMoreTile($tile, $this->style, $quantifier),
        });
    }

    private function alternation(): void
    {
        [$first, $second] = [$this->pop(), $this->pop()];
        if ($first instanceof AlternationTile) {
            $first->push($second);
            \array_unshift($this->stack, $first);

            return;
        }
        if ($second instanceof AlternationTile) {
            $second->push($first);
            \array_unshift($this->stack, $second);

            return;
        }
        $alternation = new AlternationTile();
        $alternation->push($first);
        $alternation->push($second);
        \array_unshift($this->stack, $alternation);
    }

    /** Two tiles one after the other: letters run together into one box, as PlantUML runs them. */
    private function concatenation(): void
    {
        [$first, $second] = [$this->pop(), $this->pop()];
        if (self::letters($first) && self::letters($second)) {
            \array_unshift($this->stack, $second->mergedWith($first));

            return;
        }
        if ($first instanceof ConcatenationTile && self::letters($first->first()) && self::letters($second)) {
            $boxed = $first->first();
            \assert($boxed instanceof BoxTile, description: 'letters are in a box');
            $first->replaceFirst($second->mergedWith($boxed));
            \array_unshift($this->stack, $first);

            return;
        }
        if ($first instanceof ConcatenationTile) {
            $first->push($second);
            \array_unshift($this->stack, $first);

            return;
        }
        $concatenation = new ConcatenationTile();
        $concatenation->push($first);
        $concatenation->push($second);
        \array_unshift($this->stack, $concatenation);
    }

    /** @phpstan-assert-if-true BoxTile $tile */
    private static function letters(EbnfTile $tile): bool
    {
        return $tile instanceof BoxTile && EbnfSymbol::Terminal === $tile->symbol;
    }

    private function pop(): EbnfTile
    {
        return \array_shift($this->stack) ?? throw new PlantUmlRefusal('Error parsing: nothing to quantify');
    }

    /** @return list<string> a group's ranges and characters, as PlantUML's `GroupSplitter` splits them */
    private static function split(string $group): array
    {
        $characters = \mb_str_split($group);
        $count = \count($characters);
        $elements = [];
        for ($at = 0; $at < $count; ++$at) {
            if (($at + 2) < $count && '-' === $characters[$at + 1]) {
                $elements[] = $characters[$at].'-'.$characters[$at + 2];
                $at += 2;
                continue;
            }
            if (($at + 1) < $count && '\\' === $characters[$at]) {
                $escaped = $characters[$at + 1];
                $elements[] = 1 === \preg_match('/^\p{L}$/u', $escaped) ? '\\'.$escaped : $escaped;
                ++$at;
                continue;
            }
            $elements[] = $characters[$at];
        }

        return $elements;
    }

    private function peek(int $ahead): string
    {
        return $this->characters[$this->at + $ahead] ?? '';
    }

    private function take(int $count): string
    {
        $taken = \implode('', \array_slice($this->characters, $this->at, $count));
        $this->at += $count;

        return $taken;
    }

    /** `[[:alpha:]]` as `:alpha:`. */
    private function posix(): string
    {
        $this->at += 3;
        $class = ':';
        while ('' !== $this->peek(0)) {
            $character = $this->take(1);
            $class .= $character;
            if (':' === $character) {
                break;
            }
        }
        $this->at += 2;

        return $class;
    }

    private function group(): string
    {
        ++$this->at;
        $group = '';
        while ('' !== $this->peek(0)) {
            $character = $this->take(1);
            if (']' === $character) {
                break;
            }
            $group .= $character;
            if ('\\' === $character) {
                $group .= $this->take(1);
            }
        }

        return $group;
    }

    private function comment(): string
    {
        $this->at += 3;
        while (')' !== $this->peek(0)) {
            if ('' === $this->peek(0)) {
                throw new PlantUmlRefusal('Error parsing: Unclosed comment');
            }
            ++$this->at;
        }
        ++$this->at;

        return '';
    }

    private function namedGroupStarts(): bool
    {
        if ('(' !== $this->peek(0) || '?' !== $this->peek(1) || '<' !== $this->peek(2)) {
            return false;
        }
        for ($ahead = 3; '' !== $this->peek($ahead); ++$ahead) {
            $character = $this->peek($ahead);
            if ('>' === $character) {
                return 3 !== $ahead;
            }
            if (1 !== \preg_match('/^\p{L}$/u', $character)) {
                return false;
            }
        }

        return false;
    }

    private function namedGroup(): string
    {
        $this->at += 3;
        $name = '';
        while ('>' !== $this->peek(0)) {
            $name .= $this->take(1);
        }
        ++$this->at;

        return $name;
    }

    /** `(`, `(?:` or `(?!`. */
    private function open(): string
    {
        $open = $this->take(1);
        foreach (['?:', '?!'] as $mark) {
            if ($this->peek(0).$this->peek(1) !== $mark) {
                continue;
            }
            $open .= $this->take(2);
        }

        return $open;
    }

    private function quantifier(): string
    {
        $quantifier = $this->take(1);
        if ('{' === $quantifier) {
            while ('' !== $this->peek(0)) {
                $character = $this->take(1);
                $quantifier .= $character;
                if ('}' === $character) {
                    break;
                }
            }
        }
        if ('?' === $this->peek(0)) {
            $quantifier .= $this->take(1);
        }
        if ('{' === $quantifier[0] && 1 !== \preg_match('/^\{[0-9,]+\}$/', $quantifier)) {
            throw new PlantUmlRefusal('Error parsing: Bad quantifier '.$quantifier);
        }

        return $quantifier;
    }

    /** `\0nnn` or `\uhhhh`, as written. */
    private function escape(int $count): string
    {
        $escape = $this->take(1);
        for ($index = 0; $index < $count; ++$index) {
            if ('' === $this->peek(0)) {
                throw new PlantUmlRefusal('Error parsing: Unexpected end of data');
            }
            $escape .= $this->take(1);
        }

        return $escape;
    }

    /** `\p{…}` or `\x{…}`. */
    private function unicodeClass(): string
    {
        $class = '';
        while ('' !== $this->peek(0)) {
            $character = $this->take(1);
            $class .= $character;
            if ('}' === $character) {
                return $class;
            }
        }

        throw new PlantUmlRefusal('Error parsing: Unexpected end of data');
    }
}
