<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

use Janalis\Viz\Source\Unsupported;

/**
 * A block run through PlantUML's preprocessor, and what its functions write made text the
 * diagrams read: a break line splits its line, a new line is `\n`, a backslash and a tab are
 * themselves.
 */
final class PlantUmlPreprocessor
{
    /**
     * @param list<string> $lines the block, its `@start` and `@end` lines among them
     * @return list<string>
     * @throws Unsupported where PlantUML's preprocessor stops, or for what is left to PlantUML
     */
    public static function lines(array $lines): array
    {
        try {
            $result = TimContext::process($lines);
        } catch (TimError $error) {
            throw new Unsupported('PlantUML\'s preprocessor stops: '.$error->getMessage().('' === $error->source ? '' : ' — '.\trim($error->source)), previous: $error);
        } catch (JsonError $error) {
            throw new Unsupported('PlantUML\'s preprocessor stops: Fatal parsing error', previous: $error);
        }
        $text = [];
        foreach ($result as $line) {
            foreach (self::broken($line) as $piece) {
                $text[] = \strtr($piece, [
                    TimBuiltins::NEWLINE => '\n',
                    TimBuiltins::NEWLINE_LEFT => '\l',
                    TimBuiltins::NEWLINE_RIGHT => '\r',
                    TimBuiltins::BACKSLASH => '\\',
                    TimBuiltins::TABULATION => "\t",
                ]);
            }
        }

        return $text;
    }

    /**
     * A line split at each break line outside `{{ … }}`, as PlantUML's `Jaws` splits it.
     *
     * @return non-empty-list<string>
     */
    public static function broken(string $line): array
    {
        if (!\str_contains($line, TimBuiltins::BREAKLINE)) {
            return [$line];
        }
        $chars = \mb_str_split($line);
        $pieces = [];
        $pending = '';
        $level = 0;
        foreach ($chars as $at => $char) {
            $next = $chars[$at + 1] ?? '';
            $level += match (true) {
                '{' === $char && '{' === $next => 1,
                '}' === $char && '}' === $next => -1,
                default => 0,
            };
            if ($level <= 0 && TimBuiltins::BREAKLINE === $char) {
                $pieces[] = $pending;
                $pending = '';
                continue;
            }
            $pending .= $char;
        }
        $pieces[] = $pending;

        return $pieces;
    }
}
