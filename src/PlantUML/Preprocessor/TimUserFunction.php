<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * A function or procedure declared in the source, as PlantUML's `TFunctionImpl`: its
 * arguments with their defaults, and its body — or, for a legacy `!define`, its definition.
 */
final class TimUserFunction implements TimFunction
{
    /** @var list<string> */
    private array $body = [];
    private bool $returns = false;
    public ?string $definition = null;

    /**
     * @param list<array{string, ?TimValue}> $arguments each argument's name and default
     */
    public function __construct(
        private readonly string $name,
        private readonly array $arguments,
        private readonly bool $unquoted,
        private TimFunctionType $type,
        /** Whether it is declared `final`, and may be declared again no more. */
        public readonly bool $final = false,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function arity(): int
    {
        return \count($this->arguments);
    }

    #[\Override]
    public function covers(int $count, array $named): bool
    {
        $names = \array_map(static fn (array $argument): string => $argument[0], $this->arguments);
        if ([] !== \array_diff($named, $names) || $count > \count($this->arguments)) {
            return false;
        }
        $needed = 0;
        foreach ($this->arguments as [$name, $default]) {
            $needed += \in_array($name, $named, strict: true) || null !== $default ? 0 : 1;
        }

        return $count >= $needed;
    }

    #[\Override]
    public function type(): TimFunctionType
    {
        return $this->type;
    }

    #[\Override]
    public function unquoted(): bool
    {
        return $this->unquoted;
    }

    public function hasBody(): bool
    {
        return [] !== $this->body;
    }

    public function returns(): bool
    {
        return $this->returns;
    }

    public function add(string $line): void
    {
        $this->body[] = $line;
        if (TimLineType::Return !== TimLineType::of($line)) {
            return;
        }
        $this->returns = true;
        if (TimFunctionType::Procedure === $this->type) {
            throw new TimError('A procedure cannot have !return directive. Declare it as a function instead ?', $line);
        }
    }

    /** A `!definelong` of a single line is a `!define`. */
    public function finish(): void
    {
        if (TimFunctionType::LegacyDefinelong !== $this->type || 1 !== \count($this->body)) {
            return;
        }
        $this->type = TimFunctionType::LegacyDefine;
        $this->definition = $this->body[0];
    }

    #[\Override]
    public function call(TimContext $context, TimMemory $memory, array $arguments, array $named): TimValue
    {
        if (TimFunctionType::LegacyDefine === $this->type) {
            return TimValue::string($context->substituted($this->memory($memory, $arguments, []), (string) $this->definition) ?? '');
        }
        if (TimFunctionType::ReturnFunction !== $this->type) {
            throw new TimError('Illegal call here. Is there a return directive in your function?');
        }

        return $context->execute($this->memory($memory, $arguments, $named), $this->body, TimFunctionType::ReturnFunction) ?? throw new TimError('No return directive found in your function');
    }

    #[\Override]
    public function run(TimContext $context, TimMemory $memory, array $arguments, array $named): void
    {
        if (TimFunctionType::Procedure !== $this->type && TimFunctionType::LegacyDefinelong !== $this->type) {
            throw new TimError('Fatal parsing error');
        }
        $context->execute($this->memory($memory, $arguments, $named), $this->body, TimFunctionType::Procedure);
    }

    /**
     * A memory of its own holding its arguments: each named one, then each given in turn, then its default.
     *
     * @param list<TimValue> $arguments
     * @param array<string, TimValue> $named
     */
    private function memory(TimMemory $memory, array $arguments, array $named): TimMemory
    {
        $values = [];
        $next = 0;
        foreach ($this->arguments as [$name, $default]) {
            $value = $named[$name] ?? $arguments[$next] ?? $default;
            if (!\array_key_exists($name, $named) && \array_key_exists($next, $arguments)) {
                ++$next;
            }
            $values[$name] = $value ?? throw new TimError('Fatal parsing error');
        }

        return $memory->fork($values);
    }
}
