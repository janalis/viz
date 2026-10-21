<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

/**
 * A server of a network diagram, as PlantUML's `NServer` keeps one: its name, the
 * description drawn for it, its colour and shape, its column, and the networks it is on,
 * in the order it joined them, each with its address there.
 */
final class NwServer
{
    public string $description;
    public ?string $color = null;
    public string $shape = 'rectangle';
    public ?string $declaredAddress = null;
    /** Whether the link to its first network is drawn: not to one PlantUML made up for it. */
    public bool $firstLinkShown = true;
    /** @var array<int, array{NwNetwork, string}> each network it is on and its address there, by the network's object id */
    public array $connections = [];

    public function __construct(
        public readonly string $name,
        public readonly NwBar $bar = new NwBar(),
    ) {
        $this->description = $name;
    }

    public function alone(): bool
    {
        return [] === $this->connections;
    }

    /** The first network it joined. */
    public function main(): ?NwNetwork
    {
        return [] === $this->connections ? null : \array_values($this->connections)[0][0];
    }

    public function address(NwNetwork $network): ?string
    {
        return $this->connections[\spl_object_id($network)][1] ?? null;
    }

    /** As PlantUML's `connectTo`: on a network at an address — none keeping an address it already has there — its column spanning the network's stage, or from the one before it. */
    public function connect(NwNetwork $network, ?string $address): void
    {
        $address ??= '';
        $key = \spl_object_id($network);
        if ('' === $address && \array_key_exists($key, $this->connections)) {
            return;
        }
        $this->connections[$key] = [$network, $address];
        if (!$this->bar->started()) {
            $this->bar->add($network->stage);

            return;
        }
        if ($this->main() !== $network && null !== $network->up) {
            $this->bar->add($network->up);
        }
    }

    public function connectIfAlone(NwNetwork $network): void
    {
        if (!$this->alone()) {
            return;
        }
        $this->connect($network, '');
        if (!$network->visible) {
            $this->firstLinkShown = false;
        }
    }

    /** Its first address, or the one it was declared with. */
    public function someAddress(): string
    {
        $first = \array_values($this->connections)[0][1] ?? '';

        return '' !== $first ? $first : $this->declaredAddress ?? '';
    }

    public function blankFirstAddress(): void
    {
        $key = \array_key_first($this->connections);
        if (null !== $key) {
            $this->connections[$key][1] = '';
        }
    }

    /** The address it is given before it joins a network with none, as PlantUML's `learnThisAddress`. */
    public function learn(?string $address): void
    {
        foreach ($this->connections as $key => [, $known]) {
            if ('' !== $known) {
                continue;
            }
            $this->connections[$key][1] = $address ?? '';

            return;
        }
    }

    /** @param array<string, string> $properties */
    public function update(array $properties): void
    {
        $this->description = $properties['description'] ?? $this->description;
        $this->color = $properties['color'] ?? $this->color;
        $this->declaredAddress = $properties['address'] ?? $this->declaredAddress;
        $this->shape = $properties['shape'] ?? $this->shape;
    }
}
