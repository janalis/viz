<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * An EBNF diagram's commands carried out as PlantUML's `PSystemEbnf` carries them out: each
 * rule, on one line or over several up to its `;`, with a `(* comment *)` before or after
 * its name; each comment standing alone a note, on one line or over several.
 */
final class EbnfReader
{
    /** @var list<EbnfRule|list<string>> the rules and the notes, in order */
    private array $items = [];

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlEbnf
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }

        return new PlantUmlEbnf($document, $reader->items);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope' => null,
            'UBrexCommandComment' => $this->items[] = \explode('\n', \trim((string) $command->get('COMMENT'))),
            'CommandCommentMultilines2' => $this->items[] = self::comment($command->lines, 3),
            'CommandCommentMultilines' => $this->items[] = self::comment($command->lines, 2),
            'CommandEBnfSingleLine' => $this->items[] = new EbnfRule(
                EbnfExpression::read(\implode('', [$command->get('ID'), $command->get('EQUALS'), $command->get('LINE')])),
                $command->get('COMMENTA'),
                $command->get('COMMENTB'),
            ),
            'UBrexCommandEbnfMultilines' => $this->items[] = new EbnfRule(EbnfExpression::read(\implode('', \array_map(\trim(...), $command->lines)))),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /**
     * A comment over several lines, its marks taken off its first line's start and its last's end.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function comment(array $lines, int $marks): array
    {
        $lines = \array_map(\trim(...), $lines);
        $last = \count($lines) - 1;
        $lines[0] = \trim(\substr($lines[0], $marks));
        $lines[$last] = \trim(\substr($lines[$last], offset: 0, length: -$marks));

        return $lines;
    }
}
