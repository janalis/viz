<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

use Janalis\Viz\Source\Unsupported;

/**
 * A PlantUML source as PlantUML reads it before any diagram does: a line ending in one
 * backslash joined to the next, the one `@startuml` … `@enduml` block (`\` may stand for
 * `@`), its comments dropped — lines starting with `'`, and `/'` … `'/` within a line or over
 * several — and the preprocessor's directives taken: `!pragma`s are kept, and any other
 * directive, which only PlantUML's own preprocessor expands, is left to it.
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

    /** @throws Unsupported when there is no block, several, one of another kind than uml, or a preprocessor directive */
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
                [$open, $kind, $body] = [true, \strtolower($match[1]), []];
                continue;
            }
            if (!$open) {
                continue;
            }
            if (1 === \preg_match('/^[@\\\\]end/i', $trimmed)) {
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

        return self::preprocessed(self::uncommented($bodies[0]));
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

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function uncommented(array $lines): array
    {
        $kept = [];
        $long = false;
        foreach ($lines as $line) {
            $trimmed = \trim(\str_replace("\t", replace: ' ', subject: $line));
            if ($long) {
                $long = !\str_ends_with($trimmed, "'/");
                continue;
            }
            if (\str_starts_with($trimmed, "'") || (\str_starts_with($trimmed, "/'") && \str_ends_with($trimmed, "'/"))) {
                continue;
            }
            if (\str_starts_with($trimmed, "/'") && !\str_contains($trimmed, "'/")) {
                $long = true;
                continue;
            }
            $kept[] = self::inner($line, $trimmed);
        }

        return $kept;
    }

    /** A line without the comment that opens or closes it. */
    private static function inner(string $line, string $trimmed): string
    {
        $open = \strpos($line, needle: "/'");
        $close = \strpos($line, needle: "'/");
        if (\str_starts_with($trimmed, "/'") && false !== $close) {
            return \substr($line, $close + 2);
        }
        if (\str_ends_with($trimmed, "'/") && false !== $open) {
            return \substr($line, offset: 0, length: (int) \strrpos($line, needle: "/'"));
        }

        return $line;
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
            if (\str_starts_with($trimmed, '!') || 1 === \preg_match('/^%\w+\(|\$\w+\s*=/', $trimmed)) {
                throw new Unsupported('The native PlantUML renderer leaves the preprocessor to PlantUML: '.\explode(separator: ' ', string: $trimmed, limit: 2)[0]);
            }
            $kept[] = $line;
        }

        return new self($kept, $pragmas);
    }
}
