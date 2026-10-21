<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\ChenEer;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassColors;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A Chen EER diagram's commands carried out as PlantUML's `ChenEerDiagram` carries them out:
 * `entity` and `relationship` blocks, the attributes inside them — and inside a `{` attribute
 * — each linked to its owner, from above; `a -1- b` and `a =N= b` associations, `=` a bold
 * line; `a ->- b` subclass links, and `a ->- d { b, c }` through a circle, each with a subset
 * sign across its middle; the layout direction.
 */
final class ChenReader
{
    /** @var array<string, ChenElement> */
    private array $elements = [];
    /** @var list<PlantUmlLink> */
    private array $links = [];
    /** @var array<int, bool> by link, the subset sign across its middle: true opening towards its head, as PlantUML's superset */
    private array $signs = [];
    /** @var list<string> the elements whose attributes follow, innermost last */
    private array $owners = [];
    private Direction $direction = Direction::TopBottom;

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlChen
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }

        return new PlantUmlChen($document, $reader->elements, $reader->links, $reader->signs, $reader->direction);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope' => null,
            'CommandRankDir' => $this->direction = \str_starts_with(\strtolower($command->line()), 'left') ? Direction::LeftRight : Direction::TopBottom,
            'CommandCreateEntity' => $this->entity($command),
            'CommandCreateAttribute' => $this->attribute($command),
            'CommandAssociate' => $this->associate($command),
            'CommandEndGroup' => [] === $this->owners ? throw new PlantUmlRefusal('Unbalanced brackets') : \array_pop($this->owners),
            'CommandSimpleSubclass' => $this->subclass($command),
            'CommandMultiSubclass' => $this->subclasses($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /** `entity NAME {`, `relationship "Shown" as NAME <<stereotype>> #colour {`: its attributes follow. */
    private function entity(PlantUmlCommand $command): void
    {
        $id = self::clean((string) $command->get('CODE'));
        $kind = 'entity' === $command->get('TYPE') ? ChenKind::Entity : ChenKind::Relationship;
        $existing = $this->elements[$id] ?? null;
        if (null !== $existing && $existing->kind !== $kind) {
            throw new PlantUmlRefusal('Bad name');
        }
        $stereotype = $command->get('STEREO');
        $this->elements[$id] = new ChenElement(
            $id,
            $kind,
            ClassReader::split($command->get('DISPLAY') ?? $id),
            null === $stereotype ? $existing->stereotypes ?? [] : ClassReader::stereotypes($stereotype),
            self::color($command),
        );
        $this->owners[] = $id;
    }

    /** `Name`, `"Shown" as Name <<key>> #colour`, linked to its owner; `Name {` owning those that follow. */
    private function attribute(PlantUmlCommand $command): void
    {
        $owner = [] === $this->owners ? throw new PlantUmlRefusal('Attribute must be inside an entity, relationship or another attribute') : $this->owners[\count($this->owners) - 1];
        $name = self::clean(\trim((string) $command->get('CODE')));
        $id = $owner.'/'.$name;
        if (\array_key_exists($id, $this->elements)) {
            throw new PlantUmlRefusal('Attribute already exists');
        }
        $stereotype = $command->get('STEREO');
        $color = self::color($command);
        $this->elements[$id] = new ChenElement(
            $id,
            ChenKind::Attribute,
            ClassReader::split($command->get('DISPLAY') ?? $name),
            null === $stereotype ? [] : ClassReader::stereotypes($stereotype),
            line: $color,
        );
        $this->link($id, $owner, 2, color: $color);
        if (null !== $command->get('COMPOSITE')) {
            $this->owners[] = $id;
        }
    }

    /** `A -1- B`, `A =(1,N)= B #colour`: its cardinality in its middle, two ranks long. */
    private function associate(PlantUmlCommand $command): void
    {
        $this->link($this->existing($command->get('NAME1')), $this->existing($command->get('NAME2')), 3, '=' === $command->get('PARTICIPATION'), self::color($command), $command->get('CARDINALITY'));
    }

    /** `A ->- B`, the subset sign opening towards B; `A -<- B`, away from it. */
    private function subclass(PlantUmlCommand $command): void
    {
        $this->link($this->existing($command->get('NAME1')), $this->existing($command->get('NAME2')), 3, '=' === $command->get('PARTICIPATION'), self::color($command));
        $this->signs[\count($this->links) - 1] = '>' === $command->get('DIRECTION');
    }

    /**
     * `A ->- d { B, C }`: a circle with the symbol — `d`, `o` or `U` — the superclass linked to
     * it, it to each subclass; the signs across the subclasses' links, or the superclass's for `U`.
     */
    private function subclasses(PlantUmlCommand $command): void
    {
        $superclass = self::clean((string) $command->get('SUPERCLASS'));
        $symbol = (string) $command->get('SYMBOL');
        $written = (string) $command->get('SUBCLASSES');
        $color = self::color($command);
        $circle = $superclass.'/'.$symbol.$written.'/center';
        $this->elements[$circle] = new ChenElement($circle, ChenKind::Circle, [$symbol], back: $color);
        $this->link($this->existing($superclass), $circle, 2, '=' === $command->get('PARTICIPATION'), $color);
        if ('U' === $symbol) {
            $this->signs[\count($this->links) - 1] = true;
        }
        foreach (\explode(',', $written) as $subclass) {
            $this->link($circle, $this->existing(\trim($subclass)), 3, color: $color);
            if ('U' !== $symbol) {
                $this->signs[\count($this->links) - 1] = true;
            }
        }
    }

    private function link(string $from, string $to, int $length, bool $bold = false, ?string $color = null, ?string $label = null): void
    {
        $link = new PlantUmlLink($from, $to, LinkDecor::None, LinkDecor::None, $length);
        $link->bold = $bold;
        $link->color = $color;
        $link->label = $label;
        $this->links[] = $link;
    }

    private function existing(?string $name): string
    {
        $id = self::clean((string) $name);

        return \array_key_exists($id, $this->elements) ? $id : throw new PlantUmlRefusal('No such entity: '.$id);
    }

    /** The colour written after it, as hex. */
    private static function color(PlantUmlCommand $command): ?string
    {
        $color = $command->get('COLOR');

        return null === $color ? null : ClassColors::back($color);
    }

    private static function clean(string $name): string
    {
        $name = \trim($name);

        return \strlen($name) >= 2 && \str_starts_with($name, '"') && \str_ends_with($name, '"') ? \substr($name, offset: 1, length: -1) : $name;
    }
}
