<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

/**
 * A player of a timing diagram, as PlantUML's `Player` and its kinds keep one: `robust`,
 * `concise`, `rectangle`, `binary` or `clock`; its title, whether it is compact, its colour,
 * the height asked for it, its states' labels, its first state and its changes in time — a
 * binary player's last change at a moment taking it, any other's first — its constraints and
 * notes; a clock's period, pulse and offset.
 */
final class TimingPlayer
{
    /** @var array<string, string> each state's label, by its code */
    public array $labels = [];
    public ?TimingChange $initial = null;
    /** @var array<string, TimingChange> by moment, in no order */
    private array $changes = [];
    /** @var list<TimingConstraint> */
    public array $constraints = [];
    /** @var list<TimingNote> */
    public array $notes = [];

    /** @param list<string> $stereotypes */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly bool $compact,
        public int $height,
        public readonly ?string $background = null,
        public readonly array $stereotypes = [],
        public readonly float $period = 0.0,
        public readonly float $pulse = 0.0,
        public readonly float $offset = 0.0,
    ) {}

    public function change(TimingChange $change): void
    {
        if (null === $change->when) {
            $this->initial = $change;

            return;
        }
        $key = (string) $change->when->time;
        if ('binary' !== $this->kind && \array_key_exists($key, $this->changes)) {
            return;
        }
        $this->changes[$key] = $change;
    }

    /** @return list<TimingChange> in time */
    public function changes(): array
    {
        $changes = \array_values($this->changes);
        \usort($changes, static fn (TimingChange $a, TimingChange $b): int => ($a->when->time ?? 0.0) <=> ($b->when->time ?? 0.0));

        return $changes;
    }

    /** A state's label, or the state itself. */
    public function label(string $state): string
    {
        return $this->labels[$state] ?? $state;
    }
}
