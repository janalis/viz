<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/** A function or procedure of the preprocessor, built in or declared. */
interface TimFunction
{
    public function name(): string;

    /** The number of arguments it is declared with. */
    public function arity(): int;

    /** @param list<string> $named */
    public function covers(int $count, array $named): bool;

    public function type(): TimFunctionType;

    /** Whether its arguments are read as text rather than as expressions. */
    public function unquoted(): bool;

    /**
     * @param list<TimValue> $arguments
     * @param array<string, TimValue> $named
     */
    public function call(TimContext $context, TimMemory $memory, array $arguments, array $named): TimValue;

    /**
     * @param list<TimValue> $arguments
     * @param array<string, TimValue> $named
     */
    public function run(TimContext $context, TimMemory $memory, array $arguments, array $named): void;
}
