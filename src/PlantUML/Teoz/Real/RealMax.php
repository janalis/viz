<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Real;

use Janalis\Viz\Source\Unsupported;

/**
 * The greatest of several reals — or the least, for a minimum — kept from its first reading
 * on, as PlantUML keeps it.
 */
final class RealMax extends Real
{
    private ?float $cache = null;

    /** @param non-empty-list<Real> $reals */
    private function __construct(
        private readonly array $reals,
        private readonly bool $least,
    ) {
        parent::__construct($reals[0]->line);
    }

    /** @param non-empty-list<Real> $reals */
    public static function of(array $reals): self
    {
        return new self($reals, least: false);
    }

    /** @param non-empty-list<Real> $reals */
    public static function least(array $reals): self
    {
        return new self($reals, least: true);
    }

    #[\Override]
    public function value(): float
    {
        if (null === $this->cache) {
            $values = \array_map(static fn (Real $real): float => $real->value(), $this->reals);
            $this->cache = $this->least ? \min($values) : \max($values);
        }

        return $this->cache;
    }

    /** @throws Unsupported */
    #[\Override]
    public function atLeast(float $delta): Real
    {
        throw new Unsupported('A maximum of reals cannot be pushed past');
    }

    /** A minimum keeps each of its reals past another; a maximum cannot. */
    #[\Override]
    public function ensureBiggerThan(Real $other): void
    {
        if (!$this->least) {
            throw new Unsupported('A maximum of reals cannot be kept past another');
        }
        foreach ($this->reals as $real) {
            $real->ensureBiggerThan($other);
        }
    }
}
