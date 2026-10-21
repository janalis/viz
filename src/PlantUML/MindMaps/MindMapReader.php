<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A mind map's commands carried out as PlantUML's `MindMapDiagram` carries them out: ideas
 * by their depth — `*` or `#` as many as it, indented or not, `+` or `-` one more than it,
 * `-` on the left — each a new mind map when a root comes again, an ending `<<stereotype>>`
 * taken from its text, on the right unless a `left side` line puts them on the left.
 */
final class MindMapReader
{
    /** @var list<MindMap> */
    private array $maps = [];
    private MindMapSide $side = MindMapSide::Right;
    private Direction $direction = Direction::LeftRight;
    /** The depth marks of the first idea read with `*` or `#`, as PlantUML measures the others against them. */
    private ?string $first = null;

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlMindMap
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }
        if ([] === $reader->maps) {
            throw new PlantUmlRefusal('Empty description');
        }

        return new PlantUmlMindMap($document, $reader->maps, $reader->direction);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope' => null,
            'CommandRankDir' => $this->direction = \str_starts_with(\strtolower((string) $command->get('DIRECTION')), 'top') ? Direction::TopBottom : Direction::LeftRight,
            'CommandMindMapDirection' => $this->side = \in_array(\strtolower((string) $command->get('DIRECTION')), ['right', 'bottom'], strict: true) ? MindMapSide::Right : MindMapSide::Left,
            'CommandMindMapOrgmode' => $this->orgmode($command),
            'CommandMindMapOrgmodeMultiline' => $this->multiline($command),
            'CommandMindMapRoot' => $this->add(0, ClassReader::split((string) $command->get('LABEL')), null, null, $this->side),
            'CommandMindMapPlus' => $this->plus($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /** `** label`: its depth from its marks as written, indentation and all. */
    private function orgmode(PlantUmlCommand $command): void
    {
        $marks = [];
        \preg_match('/^[ \t]*[*#]+/', $command->lines[0], $marks);
        $this->add(
            $this->level($marks[0] ?? (string) $command->get('TYPE')),
            ClassReader::split((string) $command->get('LABEL')),
            $command->get('BACKCOLOR'),
            $command->get('SHAPE'),
            $this->side,
        );
    }

    /** `++ label`, or `-- label` on the left: PlantUML reads it untrimmed, so not indented. */
    private function plus(PlantUmlCommand $command): void
    {
        $type = (string) $command->get('TYPE');
        if (!\str_starts_with($command->lines[0], $type)) {
            throw new PlantUmlRefusal('Syntax Error?');
        }
        $this->add(
            \strlen($type) - 1,
            ClassReader::split((string) $command->get('LABEL')),
            $command->get('BACKCOLOR'),
            $command->get('SHAPE'),
            \str_contains($type, '-') ? MindMapSide::Left : MindMapSide::Right,
        );
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
        $lines = \array_map(\trim(...), $lines);
        $this->idea(
            \strlen((string) $command->get('TYPE')) - 1,
            \array_values(\array_filter($lines, static fn (string $line): bool => !\str_starts_with($line, "'"))),
            $command->get('BACKCOLOR'),
            $command->get('SHAPE'),
            $this->side,
            $stereotype,
        );
    }

    /**
     * An idea whose text may end with its stereotype.
     *
     * @param list<string> $label
     */
    private function add(int $level, array $label, ?string $color, ?string $shape, MindMapSide $side): void
    {
        $last = \count($label) - 1;
        $match = [];
        $stereotype = null;
        if (1 === \preg_match('/^(.*?)(<<\s*(.*)\s*>>)\s*$/', $label[$last], $match)) {
            $label[$last] = $match[1];
            $stereotype = $match[2];
        }
        $this->idea($level, $label, $color, $shape, $side, $stereotype);
    }

    /**
     * An idea, a root starting a new mind map as does the first whatever its depth.
     *
     * @param list<string> $label
     */
    private function idea(int $level, array $label, ?string $color, ?string $shape, MindMapSide $side, ?string $stereotype): void
    {
        if ([] === $this->maps || 0 === $level) {
            $this->maps[] = new MindMap($label, '_' !== $shape, $color, $stereotype);

            return;
        }
        $this->maps[\count($this->maps) - 1]->add($level, $label, '_' !== $shape, $color, $stereotype, $side);
    }

    /** As PlantUML's `getSmartLevel`: the depth of marks against the first ones read, spaces and tabs counting. */
    private function level(string $type): int
    {
        $this->first ??= $type;
        $type = \str_replace("\t", replace: ' ', subject: \str_ends_with($type, '**') ? \trim($type) : $type);
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
