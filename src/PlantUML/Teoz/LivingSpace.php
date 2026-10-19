<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceParticipant;
use Janalis\Viz\PlantUML\Teoz\Real\Real;
use Janalis\Viz\PlantUML\Teoz\Real\RealDeltaLive;
use Janalis\Viz\PlantUML\Teoz\Rose\ActiveLine;
use Janalis\Viz\PlantUML\Teoz\Rose\Area;
use Janalis\Viz\PlantUML\Teoz\Rose\Component;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\Rose;

/**
 * A participant's column, as teoz lays it out: its head's left edge (B), its lifeline (C),
 * its head's right edge (D), and the margins (A, E) notes and loops claim either side; its
 * lifeline, cut where it is created and destroyed and dotted along delays, and its activations.
 */
final class LivingSpace
{
    private ?Real $posC = null;
    private ?Real $posD = null;
    private bool $created = false;
    /** @var list<array{float, bool}> where it comes alive or dies, down its lifeline */
    private array $changes = [];
    /** @var list<array{float, float}> each delay's top and height */
    private array $delays = [];
    private float $marginBefore = 0.0;
    private float $marginAfter = 0.0;
    public readonly LiveBoxes $boxes;

    public function __construct(
        public readonly SequenceParticipant $participant,
        private readonly Rose $rose,
        public readonly Real $posB,
        TeozEvents $events,
    ) {
        $this->boxes = new LiveBoxes($participant, $events, $rose);
    }

    public function head(): Component
    {
        return $this->rose->participant($this->participant, head: true);
    }

    /** @return array{float, float} */
    public function headSize(): array
    {
        return $this->head()->size();
    }

    public function posC(): Real
    {
        return $this->posC ??= $this->posB->plus($this->headSize()[0] / 2);
    }

    /** Its lifeline, moved right past its deepest activation. */
    public function posC2(): Real
    {
        return $this->posC()->plus((ActiveLine::WIDTH / 2) * $this->boxes->maxLevel());
    }

    public function posD(): Real
    {
        return $this->posD ??= $this->posB->plus($this->headSize()[0]);
    }

    /** Its left margin's edge, as wide as it is when asked. */
    public function posA(): Real
    {
        return $this->posB->plus(-$this->marginBefore);
    }

    /** Its right margin's edge, as wide as it is when asked. */
    public function posE(): Real
    {
        return $this->posD()->plus($this->marginAfter);
    }

    /** Its left margin's edge, as wide as it is when the layout is solved. */
    public function posALive(): Real
    {
        return new RealDeltaLive($this->posB, fn (): float => -$this->marginBefore);
    }

    /** Its right margin's edge, as wide as it is when the layout is solved. */
    public function posELive(): Real
    {
        return new RealDeltaLive($this->posD(), fn (): float => $this->marginAfter);
    }

    public function ensureMarginBefore(float $margin): void
    {
        $this->marginBefore = \max($this->marginBefore, $margin);
    }

    public function ensureMarginAfter(float $margin): void
    {
        $this->marginAfter = \max($this->marginAfter, $margin);
    }

    public function levelAt(SequenceEvent $event, History $mode): int
    {
        return $this->boxes->levelAt($event, $mode);
    }

    public function addStep(SequenceEvent $event, float $y): void
    {
        $this->boxes->addStep($event, $y);
    }

    /** It is created by a message: its head stands there, not at the top. */
    public function create(?float $y = null): void
    {
        $this->created = true;
        if (null !== $y) {
            $this->change($y, alive: true);
        }
    }

    public function created(): bool
    {
        return $this->created;
    }

    public function destroy(float $y): void
    {
        $this->change($y, alive: false);
    }

    public function delayOn(float $y, float $height): void
    {
        $this->delays[] = [$y, $height];
        $this->boxes->delayOn($y, $height);
    }

    /**
     * Its head with its top left at a point.
     *
     * @return list<Mark>
     */
    public function headMarks(float $x, float $y, Layer $layer): array
    {
        return self::placed($this->head(), $x, $y, $layer);
    }

    /**
     * Its foot with its top left at a point.
     *
     * @return list<Mark>
     */
    public function footMarks(float $x, float $y): array
    {
        return self::placed($this->rose->participant($this->participant, head: false), $x, $y, Layer::Foreground);
    }

    /** @return list<Mark> */
    private static function placed(Component $component, float $x, float $y, Layer $layer): array
    {
        [$width, $height] = $component->size();

        return $component->marks($x, $y, new Area($width, $height), $layer);
    }

    /**
     * Its lifeline where it is alive and its activations, down from a point to a height.
     *
     * @return list<Mark>
     */
    public function lifeMarks(float $top, float $height): array
    {
        $x = $this->posC()->value();
        $marks = [];
        $alive = !$this->created;
        $since = 0.0;
        $changes = $this->changes;
        \usort($changes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($changes as [$y, $living]) {
            if (!$alive && $living) {
                $since = $y;
            }
            if ($alive && !$living) {
                \array_push($marks, ...$this->line($x, $top, $since, $y));
            }
            $alive = $living;
        }
        if ($alive) {
            \array_push($marks, ...$this->line($x, $top, $since, $height));
        }
        $first = \array_values(\array_filter($changes, static fn (array $change): bool => $change[1]))[0][0] ?? 0.0;

        return [...$marks, ...$this->boxes->marks($x, $top, $first, $height)];
    }

    /** @return list<Mark> its lifeline from a point down to another, dotted along the delays wholly between them */
    private function line(float $x, float $top, float $from, float $to): array
    {
        $delays = $this->delays;
        \usort($delays, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $marks = [];
        $y = $from;
        foreach ($delays as [$start, $height]) {
            if ($start < $from || ($start + $height) > $to) {
                continue;
            }
            \array_push($marks, ...$this->stretch($this->rose->lifeLine($this->participant), $x, $top, $y, $start));
            \array_push($marks, ...$this->stretch($this->rose->delayLine(), $x, $top, $start, $start + $height));
            $y = $start + $height;
        }

        return [...$marks, ...$this->stretch($this->rose->lifeLine($this->participant), $x, $top, $y, $to)];
    }

    /** @return list<Mark> */
    private function stretch(Component $line, float $x, float $top, float $from, float $to): array
    {
        if ($to <= $from) {
            return [];
        }

        return $line->marks($x, $top + $from, new Area($line->size()[0], $to - $from), Layer::Foreground);
    }

    private function change(float $y, bool $alive): void
    {
        $this->changes = \array_values(\array_filter($this->changes, static fn (array $change): bool => $change[0] !== $y));
        $this->changes[] = [$y, $alive];
    }
}
