<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Board;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A board's lines carried out as PlantUML's `BoardDiagram` carries them out: a line without
 * `+` an activity; one with them a release at that stage of the last activity, under the
 * last card of a stage above it.
 */
final class BoardReader
{
    /** @var list<BoardCard> */
    private array $activities = [];
    private ?BoardCard $cursor = null;

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlBoard
    {
        $reader = new self();
        foreach ($commands as $command) {
            match ($command->name) {
                'CommandNope' => null,
                'CommandBoardPlus' => $reader->line(\strlen((string) $command->get('PLUS')), (string) $command->get('LABEL')),
                default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
            };
        }

        return new PlantUmlBoard($document, $reader->activities);
    }

    private function line(int $stage, string $text): void
    {
        if (0 === $stage) {
            $this->cursor = new BoardCard($text, 0);
            $this->activities[] = $this->cursor;

            return;
        }
        $cursor = $this->cursor ?? throw new PlantUmlRefusal('A release before any activity');
        while ($stage <= $cursor->stage) {
            $cursor = $cursor->parent ?? throw new PlantUmlRefusal('No card above the release');
        }
        $card = new BoardCard($text, $stage, $cursor);
        $cursor->children[] = $card;
        $this->cursor = $card;
    }
}
