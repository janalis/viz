<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Git;

/** PlantUML's `GNode`: commits drawn in one box, a run of them with one parent and one child each, and the first one's comment. */
final class GitNode
{
    /** @var list<self> its children, the commits after it */
    public array $up = [];
    /** @var list<self> its parents */
    public array $down = [];

    /** @param list<string> $texts */
    public function __construct(
        public array $texts,
        public readonly ?string $comment,
    ) {}

    public function top(): bool
    {
        return [] === $this->up;
    }

    /** As PlantUML's `canEatTheNextOne`: one child and one parent, the parent one of each too. */
    public function canEat(): bool
    {
        if (1 !== \count($this->up) || 1 !== \count($this->down)) {
            return false;
        }
        $next = $this->down[0];

        return 1 === \count($next->up) && 1 === \count($next->down);
    }

    /** As PlantUML's `eatTheNextOne`: its parent's commits taken in, the parent's parent its own. */
    public function eat(): self
    {
        $removed = $this->down[0];
        $after = $removed->down[0];
        $this->texts = [...$this->texts, ...$removed->texts];
        $this->down[0] = $after;
        $after->up = [...\array_values(\array_filter($after->up, static fn (self $node): bool => $node !== $removed)), $this];

        return $removed;
    }
}
