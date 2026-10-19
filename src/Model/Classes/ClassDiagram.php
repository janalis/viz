<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;

/** Classifiers and their members, the relations between them, packages and notes. */
final readonly class ClassDiagram implements Diagram
{
    /**
     * @param list<Classifier> $classifiers
     * @param list<Relation>   $relations
     * @param list<Package>    $packages top-level packages
     * @param list<Note>       $notes
     */
    public function __construct(
        public array $classifiers = [],
        public array $relations = [],
        public array $packages = [],
        public array $notes = [],
        public Direction $direction = Direction::TopBottom,
        public string $title = '',
    ) {}

    #[\Override]
    public function family(): Family
    {
        return Family::Classes;
    }

    #[\Override]
    public function graph(): Graph
    {
        return ClassLowering::graph($this);
    }

    /** @return array<string, string> the package holding each classifier, by classifier id */
    public function owners(): array
    {
        return self::owned($this->packages);
    }

    /**
     * @param list<Package> $packages
     * @return array<string, string>
     */
    private static function owned(array $packages): array
    {
        $owners = [];
        foreach ($packages as $package) {
            foreach ($package->classifiers as $classifier) {
                $owners[$classifier] = $package->id;
            }
            $owners = \array_replace($owners, self::owned($package->packages));
        }

        return $owners;
    }
}
