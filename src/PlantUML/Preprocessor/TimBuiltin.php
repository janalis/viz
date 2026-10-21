<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/** A function PlantUML's preprocessor has built in: `%strlen`, `%darken` and the like. */
final readonly class TimBuiltin implements TimFunction
{
    /**
     * @param int $arity the number of arguments it is declared with, which need not be one it takes
     * @param array{int, ?int} $counts the least and the most arguments it takes, null for no most
     * @param \Closure(TimContext, TimMemory, list<TimValue>, array<string, TimValue>): TimValue $body
     */
    public function __construct(
        private string $name,
        private int $arity,
        private array $counts,
        private \Closure $body,
        private TimFunctionType $type = TimFunctionType::ReturnFunction,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function arity(): int
    {
        return $this->arity;
    }

    #[\Override]
    public function covers(int $count, array $named): bool
    {
        [$least, $most] = $this->counts;

        return $count >= $least && (null === $most || $count <= $most);
    }

    #[\Override]
    public function type(): TimFunctionType
    {
        return $this->type;
    }

    #[\Override]
    public function unquoted(): bool
    {
        return false;
    }

    #[\Override]
    public function call(TimContext $context, TimMemory $memory, array $arguments, array $named): TimValue
    {
        if (TimFunctionType::Procedure === $this->type) {
            throw new TimError('Fatal parsing error');
        }

        return ($this->body)($context, $memory, $arguments, $named);
    }

    #[\Override]
    public function run(TimContext $context, TimMemory $memory, array $arguments, array $named): void
    {
        if (TimFunctionType::Procedure !== $this->type) {
            throw new TimError('Fatal parsing error');
        }
        ($this->body)($context, $memory, $arguments, $named);
    }
}
