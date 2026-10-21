<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/** A regex diagram's lines carried out as PlantUML's `PSystemRegex` carries them out: each a regex, built on the one before. */
final class RegexReader
{
    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails on a line, or there is nothing to draw
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlRegex
    {
        $parser = new RegexParser(new EbnfStyle($document->styles));
        foreach ($commands as $command) {
            match ($command->name) {
                'CommandNope' => null,
                'CommandRegexfSingleLine' => $parser->line((string) $command->get('LINE')),
                default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
            };
        }

        return new PlantUmlRegex($document, $parser->top() ?? throw new PlantUmlRefusal('The regex diagram is empty'));
    }
}
