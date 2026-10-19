<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

/** A package or namespace: classifiers by id, and nested packages. */
final readonly class Package
{
    /**
     * @param list<string>  $classifiers
     * @param list<Package> $packages
     */
    public function __construct(
        public string $id,
        public string $label = '',
        public array $classifiers = [],
        public array $packages = [],
    ) {}

    public function text(): string
    {
        return '' === $this->label ? $this->id : $this->label;
    }
}
