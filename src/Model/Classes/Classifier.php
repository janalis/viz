<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

/** A class, interface, enumeration or other classifier, with its members in order. */
final readonly class Classifier
{
    /**
     * @param list<string> $stereotypes the ones its kind does not already say
     * @param list<Member> $members
     */
    public function __construct(
        public string $id,
        public ?string $label = null,
        public ClassifierKind $kind = ClassifierKind::Concrete,
        public array $stereotypes = [],
        /** Its type parameters as written, such as `T` or `K, V`. */
        public ?string $generic = null,
        public array $members = [],
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }
}
