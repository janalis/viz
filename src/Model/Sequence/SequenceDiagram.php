<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;

/** Participants in column order, the boxes around them, and the steps between them in time. */
final readonly class SequenceDiagram implements Diagram
{
    /**
     * @param list<Participant> $participants
     * @param list<Box>         $boxes
     * @param list<Step>        $steps
     */
    public function __construct(
        public array $participants = [],
        public array $boxes = [],
        public array $steps = [],
        public string $title = '',
        /** Whether each message is numbered in order. */
        public bool $autonumber = false,
    ) {}

    #[\Override]
    public function family(): Family
    {
        return Family::Sequence;
    }

    /** A sequence is about time, which a graph cannot show: it has no graph form. */
    #[\Override]
    public function graph(): ?Graph
    {
        return null;
    }

    /** @return array<string, true> the participants a lifecycle step creates, which are declared there rather than up front */
    public function created(): array
    {
        return self::creations($this->steps);
    }

    /**
     * @param list<Step> $steps
     * @return array<string, true>
     */
    private static function creations(array $steps): array
    {
        $created = [];
        foreach ($steps as $step) {
            if ($step instanceof Lifecycle && $step->create) {
                $created[$step->participant] = true;
            }
            if ($step instanceof Fragment) {
                foreach ($step->sections as $section) {
                    $created = \array_replace($created, self::creations($section->steps));
                }
            }
        }

        return $created;
    }
}
