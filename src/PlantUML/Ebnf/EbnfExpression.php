<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * An EBNF rule as PlantUML's `EbnfExpression` reads it: its tokens up to its `;` — names,
 * strings in either quotes, `? special ?` sequences, `(* comments *)`, `,`, `|`, `=`, `-`,
 * `*`, brackets and braces, `}-` for one or more — put in postfix order as its
 * `ShuntingYard` puts them, then built into a railroad as its `EbnfEngine` builds one.
 */
final class EbnfExpression
{
    /** @var list<EbnfToken> */
    private array $output = [];
    /** @var list<EbnfToken> the operator stack, its top first */
    private array $operators = [];

    /** @param list<EbnfToken> $tokens */
    private function __construct(
        public readonly array $tokens,
    ) {}

    /** @throws PlantUmlRefusal where PlantUML finds the rule unparsable: a character it does not read, or a string left open */
    public static function read(string $text): self
    {
        $characters = \mb_str_split($text);
        $tokens = [];
        $count = \count($characters);
        for ($at = 0; $at < $count; ++$at) {
            $character = $characters[$at];
            $next = $characters[$at + 1] ?? '';
            if (';' === $character) {
                break;
            }
            if (\ctype_space($character)) {
                continue;
            }
            if (self::letter($character) && '-' !== $character) {
                $literal = '';
                while ($at < $count && self::letter($characters[$at])) {
                    $literal .= $characters[$at++];
                }
                --$at;
                $tokens[] = new EbnfToken('LITERAL', $literal);
                continue;
            }
            if ('(' === $character && '*' === $next) {
                $comment = '';
                for ($at += 2; $at < $count && !('*' === $characters[$at] && ')' === ($characters[$at + 1] ?? '')); ++$at) {
                    $comment .= $characters[$at];
                }
                ++$at;
                if ('' !== \trim($comment)) {
                    $tokens[] = new EbnfToken('COMMENT_TOKEN', $comment);
                }
                continue;
            }
            if (\in_array($character, ['"', '\'', '?'], strict: true)) {
                $string = '';
                for (++$at; $at < $count && $characters[$at] !== $character; ++$at) {
                    $string .= $characters[$at];
                }
                if ($at >= $count) {
                    throw new PlantUmlRefusal('Unparsable expression');
                }
                $tokens[] = new EbnfToken(
                    match ($character) {
                        '"' => 'TERMINAL_STRING1',
                        '\'' => 'TERMINAL_STRING2',
                        default => 'SPECIAL_SEQUENCE',
                    },
                    '' === $string ? ' ' : $string,
                );
                continue;
            }
            if ('}' === $character && '-' === $next) {
                $tokens[] = new EbnfToken('REPETITION_MINUS_CLOSE');
                ++$at;
                continue;
            }
            $tokens[] = new EbnfToken(match ($character) {
                '-' => 'NOT',
                '*' => 'REPETITION_SYMBOL',
                ',' => 'CONCATENATION',
                '|' => 'ALTERNATION',
                '=' => 'DEFINITION',
                '(' => 'GROUPING_OPEN',
                ')' => 'GROUPING_CLOSE',
                '[' => 'OPTIONAL_OPEN',
                ']' => 'OPTIONAL_CLOSE',
                '{' => 'REPETITION_OPEN',
                '}' => 'REPETITION_CLOSE',
                default => throw new PlantUmlRefusal('Unparsable expression'),
            });
        }
        if ([] === $tokens) {
            throw new PlantUmlRefusal('Unparsable expression');
        }

        return new self($tokens);
    }

    /** The rule's name, or null where it has none, which PlantUML draws as a syntax error. */
    public function name(): ?string
    {
        return 'LITERAL' === $this->tokens[0]->kind && 'DEFINITION' === ($this->tokens[1] ?? null)?->kind ? $this->tokens[0]->data : null;
    }

    /** Its railroad, ringed and ended by a disc; null for a syntax error. */
    public function tile(EbnfStyle $style): ?EbnfTile
    {
        if (null === $this->name()) {
            return null;
        }
        $rest = \array_slice($this->tokens, offset: 2);
        if ([] === $rest) {
            return new CirclesTile(new EmptyTile());
        }
        try {
            $postfix = $this->postfix($rest);
        } catch (\RuntimeException|\LogicException) {
            // As PlantUML, which draws any failure here as a syntax error.
            return null;
        }

        return [] === $postfix ? null : self::built($postfix, $style);
    }

    /**
     * As PlantUML's `EbnfEngine`, a stack of tiles, each operator taking the tiles it works on
     * off its top: one left, ringed and ended by a disc, or none for a syntax error.
     *
     * @param list<EbnfToken> $postfix
     */
    private static function built(array $postfix, EbnfStyle $style): ?EbnfTile
    {
        /** @var list<EbnfTile> $stack its top first */
        $stack = [];
        $pop = static function () use (&$stack): EbnfTile {
            return \array_shift($stack) ?? throw new \LengthException('An empty stack');
        };
        try {
            foreach ($postfix as $token) {
                $tile = match (true) {
                    $token->term() => new BoxTile(
                        (string) $token->data,
                        match ($token->kind) {
                            'LITERAL' => EbnfSymbol::Literal,
                            'SPECIAL_SEQUENCE' => EbnfSymbol::Special,
                            default => EbnfSymbol::Terminal,
                        },
                        $style,
                    ),
                    $token->is('COMMENT_ABOVE', 'COMMENT_BELOW') => null,
                    $token->is('ALTERNATION') => self::alternation($pop(), $pop()),
                    $token->is('CONCATENATION') => self::concatenation($pop(), $pop()),
                    $token->is('OPTIONAL') => new OptionalTile($pop(), $style),
                    $token->is('NOT') => new NotTile($pop(), $style),
                    $token->is('REPETITION_ZERO_OR_MORE') => new OptionalTile(new OneOrMoreTile($pop(), $style), $style),
                    $token->is('REPETITION_ONE_OR_MORE') => new OneOrMoreTile($pop(), $style),
                    $token->is('REPETITION_SYMBOL') => self::counted($pop(), $pop(), $style),
                    // A bracket left open, which PlantUML's engine fails on.
                    default => throw new \LengthException($token->kind),
                };
                if (null === $tile) {
                    $top = $stack[0] ?? throw new \LengthException('Nothing to comment');
                    'COMMENT_ABOVE' === $token->kind ? $top->commentAbove((string) $token->data) : $top->commentBelow((string) $token->data);
                    continue;
                }
                \array_unshift($stack, $tile);
            }
        } catch (\LengthException) {
            return null;
        }

        return 1 === \count($stack) ? new CirclesTile($stack[0]) : null;
    }

    private static function alternation(EbnfTile $first, EbnfTile $second): EbnfTile
    {
        if ($first instanceof AlternationTile) {
            $first->push($second);

            return $first;
        }
        if ($second instanceof AlternationTile) {
            $second->push($first);

            return $second;
        }
        $alternation = new AlternationTile();
        $alternation->push($first);
        $alternation->push($second);

        return $alternation;
    }

    private static function concatenation(EbnfTile $first, EbnfTile $second): EbnfTile
    {
        if ($first instanceof ConcatenationTile) {
            $first->push($second);

            return $first;
        }
        if ($second instanceof ConcatenationTile) {
            $second->pushLast($first);

            return $second;
        }
        $concatenation = new ConcatenationTile();
        $concatenation->push($first);
        $concatenation->push($second);

        return $concatenation;
    }

    /** `3 * x`: the term repeated as often as the count before it says, optional for `0`. */
    private static function counted(EbnfTile $term, EbnfTile $count, EbnfStyle $style): EbnfTile
    {
        $label = $count->repetitionLabel();
        $tile = new OneOrMoreTile($term, $style, $label."\u{d7}");

        return '0' === $label ? new OptionalTile($tile, $style) : $tile;
    }

    /**
     * The tokens in postfix order, as PlantUML's `ShuntingYard` puts them: a comment above the
     * term after it, or below the one before an operator or a closing bracket.
     *
     * @param list<EbnfToken> $tokens
     * @return list<EbnfToken> none where a bracket or a brace is left open
     * @throws \RuntimeException where PlantUML's fails, on a bracket closed that was never opened
     */
    private function postfix(array $tokens): array
    {
        [$this->output, $this->operators] = [[], []];
        foreach ($tokens as $token) {
            match (true) {
                $token->term() => $this->term($token),
                $token->is('COMMENT_TOKEN', 'GROUPING_OPEN') => $this->push($token),
                $token->is('NOT') => $this->push(new EbnfToken('CONCATENATION'), $token),
                $token->operator() => $this->operator($token),
                $token->is('GROUPING_CLOSE') => $this->group(),
                $token->is('OPTIONAL_OPEN') => $this->push(new EbnfToken('OPTIONAL')),
                $token->is('REPETITION_OPEN') => $this->push(new EbnfToken('REPETITION_ZERO_OR_MORE')),
                $token->is('OPTIONAL_CLOSE') => $this->close('OPTIONAL', 'OPTIONAL'),
                $token->is('REPETITION_CLOSE') => $this->close('REPETITION_ZERO_OR_MORE', 'REPETITION_ZERO_OR_MORE'),
                $token->is('REPETITION_MINUS_CLOSE') => $this->close('REPETITION_ZERO_OR_MORE', 'REPETITION_ONE_OR_MORE'),
                default => throw new \UnexpectedValueException($token->kind),
            };
        }
        while (null !== ($token = $this->shift())) {
            if ($token->is('OPTIONAL', 'REPETITION_ONE_OR_MORE', 'REPETITION_ZERO_OR_MORE')) {
                return [];
            }
            $this->output[] = $token->is('COMMENT_TOKEN') ? new EbnfToken('COMMENT_BELOW', $token->data) : $token;
        }

        return $this->output;
    }

    private function term(EbnfToken $term): void
    {
        $this->output[] = $term;
        $this->comments('COMMENT_ABOVE');
    }

    private function operator(EbnfToken $operator): void
    {
        while ($this->comment() || (($this->operators[0] ?? null)?->operator() && EbnfToken::PRIORITIES[$this->operators[0]->kind] > EbnfToken::PRIORITIES[$operator->kind])) {
            $this->output[] = $this->popped('COMMENT_BELOW');
        }
        $this->push($operator);
    }

    /** Operators onto the stack, the last on top. */
    private function push(EbnfToken ...$operators): void
    {
        foreach ($operators as $operator) {
            $this->operators = [$operator, ...$this->operators];
        }
    }

    /** The top operator off the stack; null when it is empty. */
    private function shift(): ?EbnfToken
    {
        $top = $this->operators[0] ?? null;
        $this->operators = \array_slice($this->operators, offset: 1);

        return $top;
    }

    private function group(): void
    {
        while (!($this->operators[0] ?? throw new \UnderflowException('No group open'))->is('GROUPING_OPEN')) {
            $this->output[] = $this->popped('COMMENT_BELOW');
        }
        $this->shift();
    }

    /** A bracket or a brace closed: the operators inside it out, then its own as another. */
    private function close(string $open, string $as): void
    {
        while ($this->comment() || (($this->operators[0] ?? null)?->operator() ?? false)) {
            $this->output[] = $this->popped('COMMENT_BELOW');
        }
        $first = $this->shift();
        if (null === $first || $first->kind !== $open) {
            throw new \UnexpectedValueException('Unbalanced '.$open);
        }
        $this->output[] = new EbnfToken($as);
        $this->comments('COMMENT_ABOVE');
    }

    private function comments(string $as): void
    {
        while ($this->comment()) {
            $this->output[] = $this->popped($as);
        }
    }

    private function comment(): bool
    {
        return ($this->operators[0] ?? null)?->is('COMMENT_TOKEN') ?? false;
    }

    /** The top operator, a comment as one above or below its term. */
    private function popped(string $comment): EbnfToken
    {
        $top = $this->shift() ?? throw new \UnderflowException('No operator');

        return $top->is('COMMENT_TOKEN') ? new EbnfToken($comment, $top->data) : $top;
    }

    private static function letter(string $character): bool
    {
        return '-' === $character || '_' === $character || 1 === \preg_match('/^[\p{L}\p{N}]$/u', $character);
    }
}
