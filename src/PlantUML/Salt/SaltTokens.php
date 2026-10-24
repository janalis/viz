<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * A salt diagram's lines cut as PlantUML's `DataSourceImpl` cuts them: at each `|` and `}`,
 * each `{` with what opens a block — `{+`, `{S-`, … — a token of its own; each token trimmed,
 * empty ones dropped, `\|`, `\{` and `\}` kept as written; each ending its line or not.
 */
final class SaltTokens
{
    private const string BLOCK = '\{(?:[-+^#!*\/]|S-|SI|S)?';

    /** @var list<array{string, bool}> each token and whether it ends its line */
    private array $tokens = [];
    private int $at = 0;

    /** @param list<string> $lines */
    public function __construct(array $lines)
    {
        foreach ($lines as $line) {
            $escaped = \str_replace(['\|', '\{', '\}'], ["\u{e000}", "\u{e001}", "\u{e002}"], $line);
            // A split this simple never fails.
            $pieces = (array) \preg_split('/([|}])/', $escaped, flags: \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
            foreach ($pieces as $index => $piece) {
                $word = \trim((string) $piece);
                if ('|' === $word) {
                    continue;
                }
                $this->split($word, ($index + 1) === \count($pieces));
            }
        }
    }

    /** The token some way ahead. */
    public function peek(int $ahead = 0): string
    {
        return $this->tokens[$this->at + $ahead][0] ?? throw new PlantUmlRefusal('A salt block not closed');
    }

    /** @return array{string, bool} the next token, and whether it ends its line */
    public function next(): array
    {
        $word = $this->tokens[$this->at] ?? throw new PlantUmlRefusal('A salt block not closed');
        ++$this->at;

        return $word;
    }

    /** A token, each block opening in it one of its own, only the last of them ending the line the token ends. */
    private function split(string $word, bool $ends): void
    {
        $pieces = (array) \preg_split('/('.self::BLOCK.')/', $word, flags: \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        foreach ($pieces as $index => $piece) {
            $this->add((string) $piece, $ends && ($index + 1) === \count($pieces));
        }
    }

    private function add(string $word, bool $ends): void
    {
        $word = \str_replace(["\u{e000}", "\u{e001}", "\u{e002}"], ['|', '{', '}'], \trim($word));
        if ('' !== $word) {
            $this->tokens[] = [$word, $ends];
        }
    }
}
