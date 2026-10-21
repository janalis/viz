<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * The functions and procedures the preprocessor knows, as PlantUML's `FunctionsSet`: by name
 * and number of arguments, found where a call starts in a line by their name and `(`, and the
 * one being declared, whose body the lines after it make until its end.
 */
final class TimFunctions
{
    /** @var array<string, TimFunction> by name and number of arguments */
    private array $functions = [];
    /** @var array<string, true> the signatures declared `final` */
    private array $final = [];
    private readonly TimTrie $calls;
    /** @var array<string, true> the first characters of their names */
    private array $starts = [];
    public ?TimUserFunction $pending = null;

    public function __construct()
    {
        $this->calls = new TimTrie();
    }

    public function add(TimFunction $function): void
    {
        if ($function instanceof TimUserFunction) {
            $function->finish();
        }
        $this->put($function);
    }

    private function put(TimFunction $function): void
    {
        $this->functions[$function->name().'/'.$function->arity()] = $function;
        $this->calls->add($function->name().'(');
        $this->starts[\mb_substr($function->name(), start: 0, length: 1)] = true;
    }

    /**
     * The function a call means: the one of its name and number of arguments, or the first of
     * its name that takes them.
     *
     * @param list<string> $named
     */
    public function find(string $name, int $count, array $named = []): ?TimFunction
    {
        $exact = $this->functions[$name.'/'.$count] ?? null;
        if (null !== $exact) {
            return $exact;
        }
        foreach ($this->functions as $function) {
            if ($function->name() === $name && $function->covers($count, $named)) {
                return $function;
            }
        }

        return null;
    }

    public function exists(string $name): bool
    {
        foreach ($this->functions as $function) {
            if ($function->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /** Whether one of the name is legacy, or unquoted. */
    public function is(string $name, string $what): bool
    {
        foreach ($this->functions as $function) {
            if ($function->name() === $name && ('legacy' === $what ? $function->type()->isLegacy() : $function->unquoted())) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name of a function called here, as PlantUML finds it: its name and `(`, but not just
     * after a letter or a digit unless it starts with `%` or `$`.
     *
     * @param list<string> $chars
     */
    public function calledAt(array $chars, int $at): ?string
    {
        if (!\array_key_exists($chars[$at], $this->starts) || (TimContext::afterName($chars, $at) && '%' !== $chars[$at] && '$' !== $chars[$at])) {
            return null;
        }
        $found = $this->calls->longestAt($chars, $at);

        return '' === $found ? null : \mb_substr($found, start: 0, length: -1);
    }

    /** `!function`, `!procedure`, `!definelong`: the function whose body follows, or that has it already. */
    public function declare(TimUserFunction $function): void
    {
        if (null !== $this->pending) {
            throw new TimError('already0068');
        }
        $signature = $function->name().'/'.$function->arity();
        if (\array_key_exists($signature, $this->functions) && ($function->final || \array_key_exists($signature, $this->final))) {
            throw new TimError('This function is already defined');
        }
        if ($function->final) {
            $this->final[$signature] = true;
        }
        if ($function->hasBody()) {
            $this->add($function);

            return;
        }
        $this->pending = $function;
    }

    /** `!define name(…) text`: a function at once. */
    public function define(TimUserFunction $function): void
    {
        $this->put($function);
    }

    public function end(TimUserFunction $pending): void
    {
        $this->add($pending);
        $this->pending = null;
    }
}
