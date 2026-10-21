<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * The variables the preprocessor knows, as PlantUML's memories keep them: the global ones,
 * and in a function or procedure its arguments and the variables it makes — a variable it
 * sets that is global already stays global — with the decisions and loops open in it.
 */
final class TimMemory
{
    /** @var array<string, TimValue> */
    private array $variables = [];
    private readonly TimTrie $names;
    /** @var array<string, TimValue> the arguments, and what is set `local` */
    private array $overridden = [];
    private readonly TimTrie $overriddenNames;
    /** @var list<array{bool, bool}> each decision open: whether its branch holds here, and whether a branch held */
    public array $ifs = [];
    /** @var list<array{list<TimToken>, int, bool}> each loop open: its test, where it starts, and whether it is skipped */
    public array $whiles = [];
    /** @var list<array{string, ?JsonValue, int, int, bool}> each loop open: its variable, what it goes through, where it starts, its place, and whether it is skipped */
    public array $foreachs = [];

    /** @param array<string, TimValue> $arguments */
    private function __construct(
        private readonly ?self $global,
        array $arguments,
    ) {
        $this->names = new TimTrie();
        $this->overriddenNames = new TimTrie();
        foreach ($arguments as $name => $value) {
            $this->overridden[$name] = $value;
            $this->overriddenNames->add($name);
        }
    }

    public static function global(): self
    {
        return new self(null, []);
    }

    /** @param array<string, TimValue> $arguments */
    public function fork(array $arguments): self
    {
        return new self($this->global ?? $this, $arguments);
    }

    public function get(string $name): ?TimValue
    {
        if (null === $this->global) {
            return $this->variables[$name] ?? null;
        }

        return $this->overridden[$name] ?? $this->global->get($name) ?? $this->variables[$name] ?? null;
    }

    /** @param ?bool $global true for `global`, false for `local`, null for neither */
    public function put(string $name, TimValue $value, ?bool $global = null): void
    {
        if (null === $this->global) {
            if (false === $global) {
                throw new TimError('Cannot use local variable here');
            }
            $this->variables[$name] = $value;
            $this->names->add($name);

            return;
        }
        match (true) {
            true === $global => $this->global->put($name, $value, global: true),
            false === $global, \array_key_exists($name, $this->overridden) => $this->override($name, $value),
            null !== $this->global->get($name) => $this->global->put($name, $value),
            default => $this->local($name, $value),
        };
    }

    private function override(string $name, TimValue $value): void
    {
        $this->overridden[$name] = $value;
        $this->overriddenNames->add($name);
    }

    private function local(string $name, TimValue $value): void
    {
        $this->variables[$name] = $value;
        $this->names->add($name);
    }

    public function remove(string $name): void
    {
        if (null !== $this->global && \array_key_exists($name, $this->overridden)) {
            unset($this->overridden[$name]);
            $this->overriddenNames->remove($name);

            return;
        }
        if (null !== $this->global && null !== $this->global->get($name)) {
            $this->global->remove($name);

            return;
        }
        unset($this->variables[$name]);
        $this->names->remove($name);
    }

    /**
     * The longest name of a variable starting here, as PlantUML's tries find it: of a global,
     * an argument and a local one, the longest.
     *
     * @param list<string> $chars
     */
    public function nameAt(array $chars, int $at): string
    {
        $found = $this->names->longestAt($chars, $at);
        if (null === $this->global) {
            return $found;
        }
        $global = $this->global->nameAt($chars, $at);
        $overridden = $this->overriddenNames->longestAt($chars, $at);
        if (\mb_strlen($global) >= \mb_strlen($overridden) && \mb_strlen($global) >= \mb_strlen($found)) {
            return $global;
        }

        return \mb_strlen($overridden) >= \mb_strlen($found) ? $overridden : $found;
    }

    /** Whether every decision open holds here. */
    public function allIfsHold(): bool
    {
        foreach ($this->ifs as [$holds]) {
            if (!$holds) {
                return false;
            }
        }

        return true;
    }
}
