<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Git;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * `git log --graph` output read as PlantUML's `GitTextArea` and `CursorPosition` read it: each
 * line with a `*` a commit, named by its first word once the graph's signs are taken out, its
 * comment what its line holds between brackets; each commit's parents found by following the
 * graph's `|`, `/`, `\` and `_` down from it to the next `* `.
 */
final readonly class GitArea
{
    /** @param list<string> $lines */
    public function __construct(
        private array $lines,
    ) {}

    /** @return list<array{string, ?string, int, int}> each commit's name, comment, column and line */
    public function commits(): array
    {
        $commits = [];
        foreach ($this->lines as $y => $line) {
            $x = \strpos($line, needle: '*');
            if (false === $x) {
                continue;
            }
            $commits[] = [self::name($line), self::comment($line), $x, $y];
        }

        return $commits;
    }

    /**
     * As PlantUML's `getAncestors`: the commits the graph leads down to from one.
     *
     * @return list<string>
     */
    public function parents(int $x, int $y): array
    {
        $candidates = [];
        if ($this->matches($x, $y + 1, '*')) {
            $candidates[] = [$x, $y + 1];
        }
        $this->above($candidates, $x, $y);
        for ($current = $x + 1;; ++$current) {
            if ($this->matches($current, $y, '.')) {
                $this->above($candidates, $current, $y);
                continue;
            }
            if (!$this->matches($current, $y, '-')) {
                break;
            }
        }
        $parents = [];
        foreach ($candidates as [$cx, $cy]) {
            [$dx, $dy] = $this->down($cx, $cy);
            $parents[] = self::name(\substr($this->line($dy), $dx));
        }

        return $parents;
    }

    /** @param list<array{int, int}> $candidates */
    private function above(array &$candidates, int $x, int $y): void
    {
        foreach ([[0, '|'], [1, '\\'], [-1, '/']] as [$dx, $sign]) {
            if (!$this->matches($x + $dx, $y + 1, $sign)) {
                continue;
            }
            $candidates[] = [$x + $dx, $y + 1];
        }
    }

    /**
     * As PlantUML's `getDownFromInternal`: down the graph, sign after sign, to a `* `. Each sign
     * leads down, or left along its line, so the way ends: at a commit, or where no sign leads on.
     *
     * @return array{int, int}
     */
    private function down(int $x, int $y): array
    {
        /** @var list<array{string, list<array{int, int, string}>, int, int}> $steps the sign here, those it needs around it, and where it goes */
        $steps = [
            ['/', [[-2, 0, '_|/']], -2, 0],
            ['_', [[-2, 0, '_|_']], -2, 0],
            ['_', [[-2, 1, '/']], -2, 1],
            ['|', [[0, 1, '* ']], 0, 1],
            ['|', [[0, 1, '|']], 0, 1],
            ['| ', [[0, 1, ' \\'], [2, 2, '|']], 2, 2],
            ['| ', [[0, 1, ' \\'], [2, 2, '\\']], 2, 2],
            ['| ', [[-1, 1, '/ '], [-2, 2, '* ']], -2, 2],
            ['| ', [[-3, 1, '_|/ ']], -3, 1],
            ['| ', [[-1, 1, '/ '], [-2, 2, '| ']], -2, 2],
            ['| ', [[-3, 1, '_|/']], -3, 1],
            ['| ', [[0, 1, ' \\'], [1, 2, '/ ']], 1, 2],
            ['| ', [[0, 1, ' \\'], [2, 2, '* ']], 2, 2],
            ['\\ ', [[1, 1, '* ']], 1, 1],
            [' \\', [], 2, 1],
            ['\\ ', [[0, 1, '/ ']], 0, 1],
            ['\\ ', [[1, 1, '\\ ']], 1, 1],
            ['\\ ', [[1, 1, '|']], 1, 1],
            ['/ ', [[-1, 1, '/ ']], -1, 1],
            ['/ ', [[-2, 1, '/| ']], -2, 1],
            ['/', [[-1, 1, '* ']], -1, 1],
            ['/', [[-1, 1, '|']], -1, 1],
        ];
        while (true) {
            if ($this->matches($x, $y, '* ')) {
                return [$x, $y];
            }
            foreach ($steps as [$sign, $around, $dx, $dy]) {
                if (!$this->matches($x, $y, $sign)) {
                    continue;
                }
                foreach ($around as [$ax, $ay, $other]) {
                    if (!$this->matches($x + $ax, $y + $ay, $other)) {
                        continue 2;
                    }
                }
                [$x, $y] = [$x + $dx, $y + $dy];
                continue 2;
            }

            throw new PlantUmlRefusal('The git graph cannot be followed at ('.($x + 1).','.($y + 1).')');
        }
    }

    private function matches(int $x, int $y, string $prefix): bool
    {
        $line = $this->line($y);

        return $x >= 0 && $x <= \strlen($line) && \str_starts_with(\substr($line, $x), $prefix);
    }

    private function line(int $y): string
    {
        return $this->lines[$y] ?? '';
    }

    /** As PlantUML's `getCommitNameInLine`: its first word, once the graph's signs are taken out. */
    private static function name(string $line): string
    {
        $text = \trim((string) \preg_replace('/[-.*|\/\\\\]/', replacement: '', subject: $line));
        $space = \strpos($text, needle: ' ');

        return false === $space ? $text : \substr($text, offset: 0, length: $space);
    }

    /** As PlantUML's `getCommentInLine`: between the first brackets after its `*`. */
    private static function comment(string $line): ?string
    {
        $star = (int) \strpos($line, needle: '*');
        $open = \strpos($line, needle: '(', offset: $star);
        $close = false === $open ? false : \strpos($line, needle: ')', offset: $open);

        return false === $open || false === $close ? null : \substr($line, $open + 1, $close - $open - 1);
    }
}
