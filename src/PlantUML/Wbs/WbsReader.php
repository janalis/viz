<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A work breakdown structure's commands carried out as PlantUML's `WBSDiagram` carries them
 * out: a single root, the first element whatever its marks; each element under the last of
 * the depth above it, its depth from its marks measured against the root's as PlantUML's
 * `getSmartLevel` measures them; on the left for `-` or `<`; named by `(code)` or `as code`
 * for the arrows between elements.
 */
final class WbsReader
{
    private ?WbsElement $root = null;
    private ?WbsElement $last = null;
    /** The root's marks, as PlantUML measures the others against them. */
    private string $first = '';
    /** @var array<string, WbsElement> */
    private array $codes = [];
    /** @var list<array{WbsElement, WbsElement, ?string, ?string}> each arrow's ends, colour and stereotype */
    private array $links = [];

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlWbs
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }
        if (null === $reader->root) {
            throw new PlantUmlRefusal('Empty description');
        }

        return new PlantUmlWbs($document, $reader->root, $reader->links);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope' => null,
            'CommandWBSItemNew', 'CommandWBSItemOld' => $this->item($command),
            'CommandWBSItemMultilineNew', 'CommandWBSItemMultilineOld' => $this->multiline($command),
            'CommandWBSLink' => $this->link($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /** `** label`, `**_`, `**[#colour](code) label`, `** "label" as code`: its depth from its marks as written, indentation and all. */
    private function item(PlantUmlCommand $command): void
    {
        $marks = [];
        \preg_match('/^[ \t]*[*+-]+/', $command->lines[0], $marks);
        $label = $command->get('LABEL');
        $shape = '_' === $command->lazy('SHAPE') ? WbsShape::None : WbsShape::Box;
        if (null === $label && WbsShape::Box === $shape) {
            throw new PlantUmlRefusal('Missing label for WBS node.');
        }
        $shape = null === $label ? WbsShape::Pseudo : $shape;
        $match = [];
        $stereotype = null;
        if (1 === \preg_match('/^\s*(.*?)\s*(<<\s*(.*)\s*>>)\s*$/', (string) $label, $match)) {
            [$label, $stereotype] = [$match[1], $match[2]];
        }
        $this->add($command, $this->level($marks[0] ?? (string) $command->get('TYPE')), ClassReader::split((string) $label), $shape, $stereotype);
    }

    /** `**:lines…;`, a `<<stereotype>>` after its `;`. */
    private function multiline(PlantUmlCommand $command): void
    {
        $lines = $command->lines;
        $last = \count($lines) - 1;
        $end = [];
        \preg_match('/^(.*);\s*(<<(.+)>>)?$/', \trim($lines[$last]), $end);
        $lines[0] = (string) $command->get('DATA');
        $lines[$last] = \substr(\trim($lines[$last]), offset: 0, length: -1);
        $stereotype = '' === ($end[2] ?? '') ? null : $end[2];
        if (null !== $stereotype) {
            $lines[$last] = $end[1];
        }
        $lines = \array_values(\array_filter(\array_map(\trim(...), $lines), static fn (string $line): bool => !\str_starts_with($line, "'")));
        $this->add($command, $this->level((string) $command->get('TYPE')), $lines, '_' === $command->lazy('SHAPE') ? WbsShape::None : WbsShape::Box, $stereotype);
    }

    /** @param list<string> $label */
    private function add(PlantUmlCommand $command, int $level, array $label, WbsShape $shape, ?string $stereotype): void
    {
        $color = $command->lazy('BACKCOLOR');
        if (0 === $level) {
            if (null !== $this->root) {
                throw new PlantUmlRefusal('WBS diagram only accepts one root first declared.');
            }
            $this->root = new WbsElement($label, 0, $shape, $color, $stereotype);
            $this->last = $this->root;

            return;
        }
        $last = $this->last ?? throw new PlantUmlRefusal('Bad tree structure');
        if ($level > ($last->level + 1)) {
            throw new PlantUmlRefusal('Bad tree structure');
        }
        $parent = $last;
        for ($up = $last->level - $level + 1; $up > 0; --$up) {
            $parent = $parent->parent ?? $parent;
        }
        $direction = $command->lazy('DIRECTION');
        $side = '<' === $direction || ('>' !== $direction && \str_contains((string) $command->get('TYPE'), '-')) ? WbsSide::Left : WbsSide::Right;
        $this->last = $parent->child($label, $level, $shape, $color, $stereotype, $side);
        $code = $command->lazy('CODE');
        if (null !== $code) {
            $this->codes[$code] = $this->last;
        }
    }

    /** `a -> b`, between elements named, in a colour or the arrow style's. */
    private function link(PlantUmlCommand $command): void
    {
        $from = (string) $command->get('CODE1');
        $to = (string) $command->get('CODE2');
        if ($from === $to) {
            throw new PlantUmlRefusal('PlantUML fails on an arrow from an element to itself: '.$command->line());
        }
        $this->links[] = [
            $this->codes[$from] ?? throw new PlantUmlRefusal('No such node '.$from),
            $this->codes[$to] ?? throw new PlantUmlRefusal('No such node '.$to),
            $command->get('COLOR'),
            $command->get('STEREOTYPE'),
        ];
    }

    /** As PlantUML's `getSmartLevel`: the root 0 whatever its marks, the others measured against them, spaces and tabs counting. */
    private function level(string $type): int
    {
        if (null === $this->root) {
            $this->first = $type;

            return 0;
        }
        $type = \str_replace("\t", replace: ' ', subject: $type);
        $first = $this->first;

        return match (true) {
            !\str_contains($type, ' ') => \strlen($type) - 1,
            \str_ends_with($type, $first) => \strlen($type) - \strlen($first),
            1 === \strlen(\trim($type)) => \strlen($type) - 1,
            \str_starts_with($type, $first) => \strlen($type) - \strlen($first),
            default => throw new PlantUmlRefusal('type=<'.$type.'>['.$first.']'),
        };
    }
}
