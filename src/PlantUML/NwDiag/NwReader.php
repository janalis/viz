<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\NwDiag;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A network diagram's commands carried out as PlantUML's `NwDiagram` carries them out:
 * `network name {` blocks with their servers and `address`, `color`, `width` and
 * `description`; `group {` blocks, not nested, naming servers; servers with their
 * attributes in brackets; `a -- b` peer links through a network PlantUML makes up and does
 * not draw; servers on no network joined to the first, drawn or not.
 */
final class NwReader
{
    /** @var array<string, NwServer> */
    private array $servers = [];
    /** @var list<NwNetwork> */
    private array $networks = [];
    /** @var list<NwGroup> */
    private array $groups = [];
    /** @var list<NwNetwork|NwGroup> the blocks open, the innermost first */
    private array $open = [];
    private NwPlayField $field;

    private function __construct()
    {
        $this->field = new NwPlayField();
    }

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlNwDiag
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }
        // As PlantUML's `makeDiagramReady`: a network made up when there is none, every server on one.
        if ([] === $reader->networks) {
            $reader->network('')->visible = false;
        }
        foreach ($reader->servers as $server) {
            $server->connectIfAlone($reader->networks[0]);
            $reader->field->add($server->bar);
        }

        return new PlantUmlNwDiag($document, $reader->networks, \array_values($reader->servers), $reader->groups, $reader->field);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandNwDiagInit', 'CommandComment', 'CommandFootboxIgnored' => null,
            'CommandNetwork' => $this->openNetwork((string) $command->get('NAME')),
            'CommandGroup' => $this->openGroup($command->get('NAME')),
            'CommandElement' => $this->element((string) $command->get('NAME'), $command->get('DEFINITION', 1)),
            'CommandLink' => $this->link((string) $command->get('NAME1'), (string) $command->get('NAME2')),
            'CommandProperty' => $this->property(\strtolower((string) $command->get('NAME')), (string) $command->get('VALUE')),
            'CommandEndSomething' => \array_shift($this->open),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    private function currentNetwork(): ?NwNetwork
    {
        foreach ($this->open as $block) {
            if ($block instanceof NwNetwork) {
                return $block;
            }
        }

        return null;
    }

    private function currentGroup(): ?NwGroup
    {
        $top = $this->open[0] ?? null;

        return $top instanceof NwGroup ? $top : null;
    }

    private function openGroup(?string $name): void
    {
        foreach ($this->open as $block) {
            if ($block instanceof NwGroup) {
                throw new PlantUmlRefusal('Cannot nest group');
            }
        }
        $group = new NwGroup($name);
        \array_unshift($this->open, $group);
        $this->groups[] = $group;
    }

    private function openNetwork(string $name): void
    {
        if (null !== $this->currentGroup()) {
            throw new PlantUmlRefusal('Cannot open network in a group');
        }
        if (null !== $this->currentNetwork()) {
            throw new PlantUmlRefusal('Cannot nest network');
        }
        if ([] === $this->networks && [] === $this->groups) {
            // The servers declared before any network, joined on a network PlantUML makes up.
            $first = null;
            // Before any network, every server is alone.
            foreach ($this->servers as $server) {
                if (null === $first) {
                    $first = $this->network('');
                    $first->visible = false;
                }
                $server->connectIfAlone($first);
            }
        }
        \array_unshift($this->open, $this->network($name));
    }

    /** A network on a stage of its own, the last stage opened before it as the one above it. */
    private function network(string $name): NwNetwork
    {
        $up = $this->field->last();
        $network = new NwNetwork($name, $this->field->newStage(), $up);
        $this->networks[] = $network;

        return $network;
    }

    /** `a -- b`: a peer, on a network made up after a's own, or on the one already made up there. */
    private function link(string $from, string $to): void
    {
        $first = $this->servers[$from] ?? null;
        if (null === $first) {
            if ([] !== $this->networks) {
                throw new PlantUmlRefusal('what about '.$from);
            }
            $network = $this->network($from);
            $server = new NwServer($to);
            $this->servers[$to] = $server;
            $server->connect($network, '');
            $this->field->add($server->bar);

            return;
        }
        if ($first->alone()) {
            if ([] === $this->networks) {
                $this->network('')->visible = false;
            }
            $first->connectIfAlone($this->networks[0]);
        }
        $index = \array_search($first->main(), $this->networks, strict: true);
        $after = false === $index ? null : $this->networks[$index + 1] ?? null;
        $network = null !== $after && !$after->visible ? $after : $this->network('');
        $network->visible = false;
        $second = $this->servers[$to] ?? null;
        if (null !== $second) {
            $first->blankFirstAddress();
            $first->connect($network, $first->someAddress());
            $second->connect($network, $second->someAddress());
            $this->field->add($second->bar);

            return;
        }
        $second = new NwServer($to, $first->bar);
        $this->servers[$to] = $second;
        $first->connect($network, '');
        $second->connect($network, '');
        $this->field->add($second->bar);
    }

    /** A server, with its `address`, `color`, `description` and `shape`, on the network open — or, outside one, only described. */
    private function element(string $name, ?string $definition): void
    {
        $properties = self::attributes($definition);
        $this->servers[$name] ??= new NwServer($name);
        $server = $this->servers[$name];
        $group = $this->currentGroup();
        if (null !== $group) {
            foreach ($this->groups as $other) {
                if ($other->contains($name)) {
                    throw new PlantUmlRefusal('Element already in another group.');
                }
            }
            $group->names[$name] = true;
            if (null === $this->currentNetwork()) {
                $server->update($properties);

                return;
            }
        }
        $network = $this->currentNetwork();
        if ([] === $this->networks || null === $network) {
            $server->update($properties);
            $server->learn($properties['address'] ?? null);

            return;
        }
        $server->connect($network, $properties['address'] ?? null);
        $this->field->add($server->bar);
        $server->update($properties);
    }

    private function property(string $name, string $value): void
    {
        $network = $this->currentNetwork();
        $group = $this->currentGroup();
        if ('address' === $name && null !== $network) {
            $network->address = $value;
        }
        if ('width' === $name && null !== $network) {
            $network->fullWidth = 'full' === \strtolower($value);
        }
        if ('color' === $name) {
            $this->color($group ?? $network, $value);
        }
        if ('description' !== $name) {
            return;
        }
        if (null !== $group) {
            $group->description = $value;

            return;
        }
        if (null === $network) {
            throw new PlantUmlRefusal('A description outside any network');
        }
        $network->description = $value;
    }

    /** The colour of the group open, or else of the network open. */
    private function color(NwGroup|NwNetwork|null $block, string $value): void
    {
        if (null === $block) {
            return;
        }
        $block->color = $value;
    }

    /** @return array<string, string> `name = value` pairs, a value quoted or not, as PlantUML's `toSet` reads them */
    public static function attributes(?string $written): array
    {
        $matches = [];
        \preg_match_all('/\s*(\w+)\s*=\s*("([^"]*)"|[^\s,]+)/', (string) $written, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[3] ?? $match[2];
        }

        return $attributes;
    }
}
