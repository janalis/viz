<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;

/**
 * The kinds of diagram a `@startuml` block may hold that no native family reads, known by
 * the lines PlantUML recognises them by, so they go to PlantUML rather than to a native
 * family that might misread them.
 */
final class PlantUmlKinds
{
    /** @var array<string, string> what each pattern finds, by pattern on a trimmed line */
    private const array CLAIMS = [
        '/^salt$|^\{[+#!^*-]?$/i' => 'salt wireframes',
        '/^(?:nwdiag|packetdiag|rackdiag)[\s\x{a0}]*\{/iu' => 'network diagrams',
        '/^(?:strict[\s\x{a0}]+)?(?:di)?graph\b[^{]*\{$/iu' => 'embedded DOT',
        '/^ditaa\b/i' => 'ditaa',
        '/^(?:latex|math)\b/i' => 'formulas',
        '/^(?:robust|concise|clock|binary|analog)[\s\x{a0}]/iu' => 'timing diagrams',
        '/^@[+\d]/' => 'timing diagrams',
        '/^\[[^\]]+\][\s\x{a0}]+(?:lasts|requires|starts|ends|happens|is[\s\x{a0}]+colored|displays)\b/iu' => 'Gantt charts',
        '/^project[\s\x{a0}]+starts\b/iu' => 'Gantt charts',
        '/\(\*(?:top)?\)/i' => 'the legacy activity syntax',
        '/^(?:license|version|help|listfonts|listopeniconic|listsprites?|stdlib|colors|donors|keywords?)\b/i' => 'PlantUML\'s information pages',
    ];

    /** What the block holds that no native family reads, or null. */
    public static function claimed(PlantUmlDocument $document): ?string
    {
        foreach ($document->lines as $line) {
            $line = \trim($line);
            foreach (self::CLAIMS as $pattern => $kind) {
                if (1 === \preg_match($pattern, $line)) {
                    return $kind;
                }
            }
        }

        return null;
    }
}
