<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

use Janalis\Viz\PlantUML\Preprocessor\PlantUmlPreprocessor;
use Janalis\Viz\Source\Unsupported;

/**
 * A PlantUML source as PlantUML reads it before any diagram does: a line ending in one
 * backslash joined to the next, the one `@startuml` … `@enduml` block (`\` may stand for
 * `@`) run through PlantUML's preprocessor — comments dropped, variables, functions and
 * procedures carried out — and its `!pragma`s kept. A directive the preprocessor leaves to
 * the diagram, other than `!pragma`, is left to PlantUML.
 */
final readonly class PlantUmlSource
{
    /**
     * @param list<string> $lines the block's lines between its start and end, comments dropped
     * @param array<string, string> $pragmas by lower-case name
     */
    public function __construct(
        public array $lines,
        public array $pragmas = [],
    ) {}

    /** @throws Unsupported when there is no block, several, one of another kind than uml, or what PlantUML's preprocessor is left */
    public static function parse(string $source): self
    {
        $kinds = [];
        $bodies = [];
        $open = false;
        $kind = '';
        $body = [];
        foreach (self::joined($source) as $line) {
            $trimmed = \ltrim($line);
            $match = [];
            if (1 === \preg_match('/^[@\\\\]start(\w*)/i', $trimmed, $match)) {
                [$open, $kind, $body] = [true, \strtolower($match[1]), [$line]];
                continue;
            }
            if (!$open) {
                continue;
            }
            if (1 === \preg_match('/^[@\\\\]end/i', $trimmed)) {
                $body[] = $line;
                $kinds[] = $kind;
                $bodies[] = $body;
                $open = false;
                continue;
            }
            $body[] = $line;
        }
        if (1 !== \count($kinds)) {
            throw new Unsupported([] === $kinds ? 'The PlantUML source has no @startuml … @enduml block' : 'The PlantUML source has several diagrams');
        }
        if ('uml' !== $kinds[0]) {
            throw new Unsupported('The native PlantUML renderer does not draw @start'.$kinds[0].' diagrams');
        }

        $lines = PlantUmlPreprocessor::lines($bodies[0]);
        \array_shift($lines);
        if (1 === \preg_match('/^\s*[@\\\\]end/i', $lines[\count($lines) - 1] ?? '')) {
            \array_pop($lines);
        }

        return self::preprocessed($lines);
    }

    /** @return list<string> the source's lines, each ending in a single backslash joined to the next */
    private static function joined(string $source): array
    {
        $lines = [];
        $pending = null;
        foreach (\explode("\n", \str_replace(["\r\n", "\r"], replace: "\n", subject: $source)) as $line) {
            $line = null === $pending ? $line : $pending.$line;
            $pending = null;
            if (\str_ends_with($line, '\\') && !\str_ends_with($line, '\\\\')) {
                $pending = \substr($line, offset: 0, length: -1);
                continue;
            }
            $lines[] = $line;
        }
        if (null !== $pending) {
            $lines[] = $pending;
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private static function preprocessed(array $lines): self
    {
        $kept = [];
        $pragmas = [];
        foreach ($lines as $line) {
            $trimmed = \trim($line);
            $match = [];
            if (1 === \preg_match('/^!pragma\s+([\w.]+)(?:\s+(.*))?$/i', $trimmed, $match)) {
                $pragmas[\strtolower($match[1])] = \trim($match[2] ?? '');
                continue;
            }
            // PlantUML's `!assume transparent` does nothing any more.
            if (1 === \preg_match('/^!assume\s+transparent\s+(?:dark|light)$/i', $trimmed)) {
                continue;
            }
            if (\str_starts_with($trimmed, '!')) {
                throw new Unsupported('The native PlantUML renderer leaves '.\explode(separator: ' ', string: $trimmed, limit: 2)[0].' to PlantUML');
            }
            $kept[] = $line;
        }

        return new self($kept, $pragmas);
    }
}
