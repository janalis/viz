<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/** A salt diagram's lines gathered as PlantUML's `PSystemSalt` gathers them, then read into widgets. */
final class SaltReader
{
    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to read the diagram
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlSalt
    {
        $lines = [];
        foreach ($commands as $command) {
            if ('CommandNope' === $command->name) {
                continue;
            }
            if ('CommandAnything' !== $command->name) {
                throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line());
            }
            $line = (string) $command->get('ALL');
            if ([] === $lines && 'salt' === \trim($line)) {
                throw new PlantUmlRefusal('This is not needed anymore.');
            }
            if ('hide stereotype' !== $line) {
                $lines[] = $line;
            }
        }
        $parser = new SaltParser(new SaltTokens($lines));
        $root = $parser->root();

        return new PlantUmlSalt($document, $root, $parser->texts);
    }
}
