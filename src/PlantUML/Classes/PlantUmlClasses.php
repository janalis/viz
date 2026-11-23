<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Model\Classes\ClassDiagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\Svek\Svek;

/** A PlantUML class diagram read natively: what its lines declared, drawn as PlantUML draws it. */
final readonly class PlantUmlClasses implements PlantUmlDiagram
{
    /**
     * @param array<string, ClassEntity> $entities
     * @param list<PlantUmlLink> $links
     * @param array<string, ClassGroup> $groups
     * @param list<PlantUmlNote> $notes
     * @param list<array{bool, ?string, bool, string}> $visibility each `show` or `hide`: shown, of what, only when empty, which part
     * @param list<string> $leaves the classifiers and floating notes, by id, in the order they were made
     * @param list<string> $made the classifiers and all notes but those on links, by id, in the order they were made
     * @param array<string, int> $besides each note beside a classifier or a package, by id: how many links were written before it
     */
    public function __construct(
        public PlantUmlDocument $document,
        public array $entities,
        public array $links,
        public array $groups = [],
        public array $notes = [],
        public array $visibility = [],
        public Direction $direction = Direction::TopBottom,
        public array $leaves = [],
        public array $made = [],
        public array $besides = [],
    ) {}

    /**
     * The links, by index, and the notes beside a classifier or a package, by id, in the order
     * svek writes their edges: as svek, a note's edge to what it stands beside is a link written
     * where the note was, as the jar's layouts show.
     *
     * @return list<int|string>
     */
    public function linkOrder(): array
    {
        /** @var array<string, array{string, string}> $written */
        $written = [];
        foreach ($this->links as $index => $link) {
            $written += $this->notesBefore($index);
            $written['l'.$index] = [$link->from, $link->to];
        }
        $written += $this->notesBefore(\count($this->links));
        $order = [];
        foreach (Svek::order($written) as $key) {
            $order[] = \str_starts_with($key, 'l') ? (int) \substr($key, offset: 1) : \substr($key, offset: 1);
        }

        return $order;
    }

    /**
     * The notes beside a classifier or a package written just before a link, keyed as {@see self::linkOrder()} keys them, with their ends.
     *
     * @return array<string, array{string, string}>
     */
    private function notesBefore(int $link): array
    {
        $notes = [];
        foreach ($this->notes as $note) {
            if (($this->besides[$note->id] ?? null) !== $link) {
                continue;
            }
            $notes['n'.$note->id] = [$note->id, $note->entity ?? ''];
        }

        return $notes;
    }

    #[\Override]
    public function model(): ClassDiagram
    {
        return ClassModel::of($this);
    }

    #[\Override]
    public function drawing(): \Generator
    {
        $drawing = ClassDrawing::draw($this);
        yield from $drawing;

        return $drawing->getReturn();
    }
}
