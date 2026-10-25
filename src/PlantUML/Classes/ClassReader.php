<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * A class diagram's lines read as PlantUML's class diagram reads them: its commands matched
 * as PlantUML matches them, and those the native renderer draws carried out — classifiers
 * with their bodies, links, members added on their own line, packages, namespaces and
 * `together` blocks, notes, `hide` and `show`, and the layout direction.
 */
final class ClassReader
{
    /** A stereotype's circled character and its colour, as PlantUML's `StereotypeDecoration` reads them: `(C)` or `(C, colour)`, then its label. */
    private const string SPOT = '/^\(\s*(\S)(?:\s*,\s*(#[0-9a-fA-F]{6}|\w+)\s*)?\)\s*,?(.*)$/s';

    /** @var array<string, ClassEntity> */
    private array $entities = [];
    /** @var list<PlantUmlLink> */
    private array $links = [];
    /** @var array<string, ClassGroup> */
    private array $groups = [];
    /** @var list<string> the groups open around the current line, innermost last */
    private array $open = [];
    /** @var list<PlantUmlNote> */
    private array $notes = [];
    /** @var list<array{bool, ?string, bool, string}> show or hide, of what, only when empty, which part */
    private array $visibility = [];
    private Direction $direction = Direction::TopBottom;
    private ?string $last = null;
    private bool $mixing = false;

    /**
     * @param list<PlantUmlCommand> $commands the class diagram commands the lines matched
     * @throws Unsupported when a command is not drawn natively
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlClasses
    {
        $reader = new self();
        // A command not drawn natively is reported only once every command has run: one after it may yet make PlantUML refuse the lines.
        $unsupported = null;
        foreach ($commands as $command) {
            try {
                $reader->execute($command);
            } catch (Unsupported $notDrawn) {
                $unsupported ??= $notDrawn;
            }
        }
        if (null !== $unsupported) {
            throw $unsupported;
        }

        return new PlantUmlClasses($document, $reader->entities, $reader->links, $reader->groups, $reader->notes, $reader->visibility, $reader->direction);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandFootboxIgnored', 'CommandHideEmptyDescription' => null,
            'CommandRankDir' => $this->direction = \str_starts_with(\strtolower($command->line()), 'left') ? Direction::LeftRight : Direction::TopBottom,
            'CommandCreateClass' => $this->create($command, []),
            'CommandCreateClassMultilines' => $this->create($command, $command->body()),
            'CommandAddMethod' => $this->member($command),
            'CommandLinkClass' => $this->link($command),
            'CommandStereotype' => self::stereotyped($this->entity((string) $command->get('NAME')), (string) $command->get('STEREO')),
            'CommandPackage', 'CommandNamespace', 'CommandNamespace2', 'CommandTogether' => $this->open[] = $this->group($command),
            'CommandPackageEmpty', 'CommandNamespaceEmpty' => $this->group($command),
            'CommandEndPackage' => \array_pop($this->open),
            'CommandFactoryNote$1', 'CommandFactoryNote$2' => $this->floating($command),
            'CommandFactoryNoteOnEntity$1', 'CommandFactoryNoteOnEntity$2' => $this->beside($command),
            'CommandFactoryNoteOnLink$1', 'CommandFactoryNoteOnLink$2' => $this->onLink($command),
            'CommandHideShowByGender', 'CommandHideShow2' => $this->hide($command),
            'CommandAllowMixing' => $this->mixing = true,
            'CommandCreateElementFull2' => $this->element($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /** @param list<string> $body */
    private function create(PlantUmlCommand $command, array $body): void
    {
        $type = \strtolower((string) \preg_replace('/\s+/', replacement: ' ', subject: (string) $command->get('TYPE')));
        $known = \array_key_exists(self::clean((string) $command->lazy('CODE')), $this->entities);
        $entity = $this->entity((string) $command->lazy('CODE'), $command->lazy('DISPLAY'));
        $type = match (true) {
            \str_starts_with($type, 'abstract') => 'abstract',
            \str_starts_with($type, 'static') => 'class',
            default => $type,
        };
        // As PlantUML, a classifier declared again may change between these kinds only.
        $mutable = ['annotation', 'abstract', 'class', 'enum', 'interface', 'record', 'dataclass'];
        if ($known && $type !== $entity->type && (!\in_array($entity->type, $mutable, strict: true) || !\in_array($type, $mutable, strict: true))) {
            throw new PlantUmlRefusal('PlantUML cannot declare '.$entity->id.' again as '.$type);
        }
        $entity->type = $type;
        $entity->visibility = $command->get('VISIBILITY');
        if (null !== $command->get('STEREO')) {
            self::stereotyped($entity, (string) $command->get('STEREO'));
        }
        $entity->generic = $command->lazy('DISPLAY', 1) ?? $command->get('GENERIC') ?? $entity->generic;
        $entity->color = $command->get('COLOR') ?? $entity->color;
        $entity->body = [...$entity->body, ...$body];
        $this->last = $entity->id;
        foreach (['EXTENDS' => 'class', 'IMPLEMENTS' => 'interface'] as $keyword => $parentType) {
            $this->inherit($entity, $command->get($keyword, 1), 'interface' === $entity->type ? 'interface' : $parentType);
        }
    }

    /** `extends` and `implements`: each parent made if new, linked to the entity. */
    private function inherit(ClassEntity $entity, ?string $parents, string $type): void
    {
        if (null === $parents) {
            return;
        }
        foreach (\explode(',', \trim($parents, characters: '"')) as $id) {
            $parent = $this->entity(\trim($id));
            $parent->type = \array_key_exists($parent->id, $this->entities) && 'class' !== $parent->type ? $parent->type : $type;
            $link = new PlantUmlLink($parent->id, $entity->id, LinkDecor::Extends, LinkDecor::None, length: 2);
            $link->dashed = 'interface' === $type && 'interface' !== $entity->type;
            $this->links[] = $link;
        }
    }

    /** An element of another kind: refused without `allowmixing`, as PlantUML refuses it, and not drawn natively with it. */
    private function element(PlantUmlCommand $command): never
    {
        if (!$this->mixing && !\str_starts_with(\strtolower($command->line()), 'mix_')) {
            throw new PlantUmlRefusal('A class diagram mixes in other elements only after allowmixing');
        }

        throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line());
    }

    private function member(PlantUmlCommand $command): void
    {
        $entity = $this->entity((string) $command->get('NAME'));
        $entity->body[] = (string) $command->get('DATA');
    }

    private function link(PlantUmlCommand $command): void
    {
        $from = $command->get('ENT1');
        $to = $command->get('ENT2');
        if (null === $from || null === $to || null !== $command->get('QUALIFIER1') || null !== $command->get('QUALIFIER2') || null !== $command->get('INSIDE')) {
            throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line());
        }
        $link = ClassLinks::read($command, $this->entity($from)->id, $this->entity($to)->id);
        $this->links[] = $link;
    }

    /** A group a command declares, new or reopened; its id. */
    private function group(PlantUmlCommand $command): string
    {
        $together = 'CommandTogether' === $command->name;
        $display = $together ? '' : \trim($command->get('NAME') ?? $command->get('DISPLAY') ?? '', characters: '"');
        $id = $together ? 'together#'.\count($this->groups) : self::clean($command->get('AS') ?? $command->get('CODE') ?? $display);
        $parent = [] === $this->open ? null : $this->open[\count($this->open) - 1];
        $group = $this->groups[$id] ?? new ClassGroup($id, $display, $together ? 'together' : 'package', $parent);
        $group->stereotype = $command->get('STEREOTYPE') ?? $group->stereotype;
        $group->color = $command->get('COLOR') ?? $group->color;
        $this->groups[$id] = $group;

        return $id;
    }

    private function floating(PlantUmlCommand $command): void
    {
        $lines = 'CommandFactoryNote$1' === $command->name ? self::split((string) $command->get('DISPLAY')) : $command->body();
        $this->notes[] = new PlantUmlNote((string) $command->get('CODE'), $lines, color: $command->get('COLOR'), group: $this->current());
    }

    private function beside(PlantUmlCommand $command): void
    {
        $id = $command->get('CODE');
        $entity = null === $id ? $this->last : $this->entity($id)->id;
        if (null === $entity) {
            throw new PlantUmlRefusal('The PlantUML note has no entity to stand beside: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnEntity$1' === $command->name ? self::split((string) $command->get('NOTE')) : $command->body();
        $this->notes[] = new PlantUmlNote('note#'.\count($this->notes), $lines, $entity, \strtolower((string) $command->get('POSITION')), color: $command->get('COLOR'), group: $this->current());
    }

    private function onLink(PlantUmlCommand $command): void
    {
        if ([] === $this->links) {
            throw new PlantUmlRefusal('The PlantUML note has no link to stand on: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnLink$2' === $command->name ? self::split((string) $command->get('NOTE')) : $command->body();
        $this->notes[] = new PlantUmlNote(
            'note#'.\count($this->notes),
            $lines,
            position: \strtolower($command->get('POSITION') ?? 'bottom'),
            link: \count($this->links) - 1,
            color: $command->get('COLOR'),
        );
    }

    private function hide(PlantUmlCommand $command): void
    {
        $show = \str_starts_with(\strtolower((string) $command->get('COMMAND')), 'show');
        if ('CommandHideShow2' === $command->name) {
            $this->visibility[] = [$show, (string) $command->get('WHAT'), false, 'entity'];

            return;
        }
        $this->visibility[] = [
            $show,
            $command->get('GENDER'),
            null !== $command->get('EMPTY'),
            (string) \preg_replace('/^(member|attribute|field|method|circle|stereotype).*$/', replacement: '$1', subject: \strtolower((string) $command->get('PORTION'))),
        ];
    }

    /** An entity by name, made a class in the current group when new. */
    private function entity(string $name, ?string $display = null): ClassEntity
    {
        $id = self::clean($name);
        if (\str_contains($id, '.') || \str_contains($id, '::')) {
            throw new Unsupported('The native PlantUML renderer does not draw namespaced names such as '.$id);
        }
        if (!\array_key_exists($id, $this->entities)) {
            $this->entities[$id] = new ClassEntity($id, self::split($display ?? $id), group: $this->current());
        }
        if (null !== $display) {
            $this->entities[$id]->display = self::split($display);
        }

        return $this->entities[$id];
    }

    private function current(): ?string
    {
        return [] === $this->open ? null : $this->open[\count($this->open) - 1];
    }

    /** A name without the quotes around it. */
    private static function clean(string $name): string
    {
        $name = \trim($name);

        return \strlen($name) >= 2 && \str_starts_with($name, '"') && \str_ends_with($name, '"') ? \substr($name, offset: 1, length: -1) : $name;
    }

    /** @return list<string> text split where `\n` breaks it */
    public static function split(string $text): array
    {
        return \explode("\n", \str_replace(['\\n', '\\l', '\\r'], replace: "\n", subject: $text));
    }

    /** @return list<string> the labels of `<<a>> <<b, c>>`, each without the character `<<(C, colour) a>>` circles */
    public static function stereotypes(string $text): array
    {
        $match = [];
        \preg_match_all('/<<\s*(.*?)\s*>>/', $text, $match);
        $labels = \array_map(static fn (string $label): string => null === self::spot($label) ? $label : (string) \preg_replace(self::SPOT, replacement: '$3', subject: $label), $match[1]);

        return \array_values(\array_filter(\array_map(\trim(...), \explode(',', \implode(',', $labels))), static fn (string $label): bool => '' !== $label));
    }

    /** @return ?array{string, ?string} the character a stereotype `(C, colour) label` circles, and its colour, as PlantUML reads them; null for any other */
    private static function spot(string $label): ?array
    {
        $match = [];

        return 1 === \preg_match(self::SPOT, $label, $match) ? [$match[1], '' === $match[2] ? null : PlantUmlColor::parse($match[2])] : null;
    }

    /** An entity's stereotypes, and the character the last of them to circle one circles. */
    private static function stereotyped(ClassEntity $entity, string $text): void
    {
        $entity->stereotypes = self::stereotypes($text);
        $match = [];
        \preg_match_all('/<<\s*(.*?)\s*>>/', $text, $match);
        foreach ($match[1] as $label) {
            $entity->spot = self::spot($label) ?? $entity->spot;
        }
    }
}
