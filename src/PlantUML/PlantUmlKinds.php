<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;

/**
 * The kinds of diagram a `@startuml` block may hold that no factory here reads, known by the
 * lines PlantUML recognises them by: the legacy activity syntax, whose factory PlantUML tries
 * after class diagrams and before description ones, and the kinds it tries after all of them.
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
        '/^(?:license|version|help|listfonts|listopeniconic|listsprites?|stdlib|colors|donors|keywords?)\b/i' => 'PlantUML\'s information pages',
    ];

    /** Whether the block uses the legacy activity syntax, `(*)` for its start and end. */
    public static function legacyActivity(PlantUmlDocument $document): bool
    {
        foreach ($document->lines as $line) {
            if (1 === \preg_match('/\(\*(?:top)?\)/i', $line)) {
                return true;
            }
        }

        return false;
    }

    /** What the block holds that PlantUML's later factories read, or null. */
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
