<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wire;

use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * A wire diagram's commands carried out as PlantUML's `WireDiagram` carries them out: `*`
 * blocks, `--` columns, `goto`, `move` and `print` at the depth their indentation gives —
 * a tab or four spaces a level, any other space one more — in the last block at each level
 * above; spots; links across with `->`, `=>` and the like, down with `-->`, `==>`, each from
 * where the block's last such link left off, to the near side of the block they reach.
 */
final class WireReader
{
    /** A line of PlantUML's wire text, as its 10-point sans-serif sets one. */
    public const float LINE = 10 * 1.177_734_375;

    private WireBlock $root;
    /** @var list<WireSpot> */
    private array $spots = [];
    /** @var list<WireLink> */
    private array $links = [];
    /** @var list<array{string, string}> */
    private array $joins = [];

    private function __construct()
    {
        $this->root = new WireBlock('');
    }

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlWire
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }

        return new PlantUmlWire($document, $reader->root, $reader->spots, $reader->links, $reader->joins);
    }

    private function execute(PlantUmlCommand $command): void
    {
        $level = self::level($command);
        match ($command->name) {
            'CommandNope', 'CommandComment' => null,
            'CommandComponent' => $this->root->add(
                $level,
                (string) $command->get('NAME'),
                WireBlock::number($command->get('WIDTH')),
                WireBlock::number($command->get('HEIGHT')),
                self::color($command->get('COLOR')),
            ),
            'CommandNewColumn' => $this->root->newColumn($level),
            'CommandGoto' => $this->root->moveTo($level, WireBlock::number($command->get('X')), WireBlock::number($command->get('Y'))),
            'CommandMove' => $this->root->moveBy($level, WireBlock::number($command->get('X')), WireBlock::number($command->get('Y'))),
            'CommandPrint' => $this->print($level, (string) $command->get('TEXT')),
            'CommandSpot' => $this->spot($level, $command),
            'CommandWLink' => $this->link($level, $command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    private function print(int $level, string $text): void
    {
        $lines = ClassReader::split($text);
        $this->root->print($level, $lines, \count($lines) * self::LINE);
    }

    private function spot(int $level, PlantUmlCommand $command): void
    {
        self::flush($level);
        $name = (string) $command->get('NAME');
        $block = $this->root->find($name) ?? throw new PlantUmlRefusal('No such element '.$name);
        $this->spots[] = new WireSpot($block, self::color($command->get('COLOR')) ?? '#ff0000', $command->get('X') ?? '0', $command->get('Y') ?? '0');
    }

    private function link(int $level, PlantUmlCommand $command): void
    {
        self::flush($level);
        [$from, $to, $style] = [(string) $command->get('NAME1'), (string) $command->get('NAME2'), (string) $command->get('STYLE')];
        $first = $this->root->find($from) ?? throw new PlantUmlRefusal('No such element '.$from);
        $second = $this->root->find($to) ?? throw new PlantUmlRefusal('No such element '.$to);
        $bus = !\str_contains($style, needle: '-');
        $vertical = \str_contains($style, needle: '==') || \str_contains($style, needle: '--');
        [$x, $y] = [$command->get('X1'), $command->get('Y1')];
        $space = $bus ? 25.0 : 15.0;
        $start = $vertical ? $first->outVertical($x, $y, $space) : $first->outHorizontal($x, $y, $space);
        $message = $command->get('MESSAGE');
        $this->links[] = new WireLink(
            $start,
            $second->absolute()[$vertical ? 1 : 0],
            $vertical,
            $bus,
            \str_contains($style, needle: '>'),
            \str_contains($style, needle: '<'),
            self::color($command->get('COLOR')) ?? '#000000',
            null === $message ? null : ClassReader::split($message),
        );
        $this->joins[] = [$from, $to];
    }

    /** As PlantUML's `computeIndentationLevel`: a tab or four spaces a level, any other space one more. */
    private static function level(PlantUmlCommand $command): int
    {
        $line = $command->lines[0] ?? '';

        return \strlen(\str_replace('    ', replace: "\t", subject: \substr($line, offset: 0, length: \strlen($line) - \strlen(\ltrim($line)))));
    }

    /** A spot or a link is read from the start of its line, as PlantUML reads one indented as no command. */
    private static function flush(int $level): void
    {
        if ($level > 0) {
            throw new PlantUmlRefusal('Syntax Error?');
        }
    }

    private static function color(?string $color): ?string
    {
        if (null === $color) {
            return null;
        }

        return PlantUmlColor::parse($color) ?? throw new PlantUmlRefusal('No such color '.$color);
    }
}
