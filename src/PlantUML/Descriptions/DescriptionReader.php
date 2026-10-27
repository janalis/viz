<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Activities\ActivityReader;
use Janalis\Viz\PlantUML\Classes\ClassLinks;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * A component, deployment or use case diagram's commands carried out as PlantUML's
 * description diagram carries them out: elements by keyword or by their short forms —
 * `[component]`, `(use case)`, `:actor:`, `() interface` — links between them, containers
 * and `together` blocks, notes, and the layout direction. A name only links use becomes an
 * actor in a diagram with actors or use cases, and an interface otherwise.
 */
final class DescriptionReader
{
    /** The symbols drawn natively, and the containers among them. */
    public const array SYMBOLS = [
        'actor',
        'actor/',
        'usecase',
        'usecase/',
        'component',
        'interface',
        'node',
        'database',
        'cloud',
        'folder',
        'file',
        'artifact',
        'card',
        'rectangle',
        'agent',
        'frame',
        'package',
        'queue',
        'stack',
        'storage',
        'collections',
        'hexagon',
        'label',
        'action',
        'process',
        'person',
        'boundary',
        'control',
        'entity',
        'circle',
    ];

    /** @var array<string, DescriptionElement> */
    private array $elements = [];
    /** @var array<string, DescriptionGroup> */
    private array $groups = [];
    /** @var list<string> */
    private array $open = [];
    /** @var list<PlantUmlLink> */
    private array $links = [];
    /** @var list<PlantUmlNote> */
    private array $notes = [];
    /** @var list<array{bool, ?string}> each `hide` or `show` of stereotypes: whether it shows them, and of what, null for all */
    private array $stereotypeRules = [];
    /** @var list<array{bool, ?string, bool, string}> each `hide`, `show`, `remove` or `restore` of elements, as `ClassPortions` takes them */
    private array $visibility = [];
    private Direction $direction = Direction::TopBottom;
    /** @var list<string> the elements and floating notes, as PlantUML makes its leaves, in order */
    private array $leaves = [];
    private ?string $last = null;

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlDescriptions
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
        $reader->settle(\strtolower($document->param('componentStyle', '')), \strtolower($document->param('actorStyle', '')));
        $reader->linkedContainers();

        return new PlantUmlDescriptions($document, $reader->elements, $reader->groups, $reader->links, $reader->notes, $reader->direction, $reader->leaves, $reader->visibility);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandFootboxIgnored', 'CommandHideEmptyDescription' => null,
            'CommandHideShowByGender' => $this->hideOrShow($command),
            'CommandHideShow2', 'CommandRemoveRestore' => $this->visibility[] = [
                1 === \preg_match('/^(?:show|restore)/i', (string) $command->get('COMMAND')),
                \trim((string) $command->get('WHAT')),
                false,
                'CommandRemoveRestore' === $command->name ? 'removed' : 'entity',
            ],
            'CommandRankDir' => $this->direction = \str_starts_with(\strtolower($command->line()), 'left') ? Direction::LeftRight : Direction::TopBottom,
            'CommandCreateElementFull' => $this->element($command),
            'CommandCreateElementMultilines' => $this->multiline($command),
            'CommandLinkElement' => $this->link($command),
            'CommandPackageWithUSymbol', 'CommandTogether' => $this->open[] = $this->group($command),
            'CommandEndPackage' => \array_pop($this->open),
            'CommandFactoryNote$1', 'CommandFactoryNote$2' => $this->floating($command),
            'CommandFactoryNoteOnEntity$1', 'CommandFactoryNoteOnEntity$2' => $this->beside($command),
            'CommandFactoryNoteOnLink$1', 'CommandFactoryNoteOnLink$2' => $this->onLink($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    private function element(PlantUmlCommand $command): void
    {
        $code = (string) $command->lazy('CODE');
        $display = $command->lazy('DISPLAY');
        $symbol = $command->get('SYMBOL');
        $first = '()' === $symbol ? 'interface' : self::shortForm($code, $display);
        if (null === $symbol && null === $first) {
            // As PlantUML, a lone name is no element: the description diagram fails, and the next kind is tried.
            throw new PlantUmlRefusal('PlantUML does not read a lone name: '.$command->line());
        }
        $business = \str_ends_with((string) $symbol, '/') || \str_ends_with($display ?? $code, ')/') || \str_ends_with($display ?? $code, ':/');
        $symbol = \rtrim(\strtolower($first ?? (string) $symbol), characters: '/');
        $code = \str_starts_with($code, '()') ? \trim(\substr($code, offset: 2)) : $code;
        self::undeclared($this->elements[self::bare($code)] ?? null);
        if (\array_key_exists(self::bare($code), $this->groups)) {
            throw new PlantUmlRefusal('This element ('.self::bare($code).') is already defined');
        }
        $element = $this->get(self::bare($code), null === $display ? null : self::bare($display));
        if ('unknown' !== $element->symbol && $element->symbol !== $symbol) {
            throw new PlantUmlRefusal('This element ('.$element->id.') is already defined');
        }
        if (\in_array($symbol, DescriptionBox::PORTS, strict: true) && null === $element->group) {
            throw new Unsupported('The native PlantUML renderer draws ports only on a container: '.$element->id);
        }
        $element->symbol = $symbol;
        $element->business = $business;
        $element->stereotypes = ClassReader::stereotypes((string) $command->lazy('STEREOTYPE'));
        $element->color = PlantUmlColor::written($command->get('COLOR') ?? $command->get('COLOR2')) ?? $element->color;
        $this->last = $element->id;
    }

    /** As PlantUML, an element a link made of a bare name cannot be declared after. */
    private static function undeclared(?DescriptionElement $element): void
    {
        if (null !== $element && 'unknown' === $element->symbol) {
            throw new PlantUmlRefusal('This element ('.$element->id.') is already defined');
        }
    }

    /** `rectangle R [` … `]` and `component C "` … `"`: an element whose text runs over several lines. */
    private function multiline(PlantUmlCommand $command): void
    {
        $symbol = \rtrim(\strtolower((string) $command->get('TYPE')), characters: '/');
        if (!\in_array($symbol, self::SYMBOLS, strict: true)) {
            throw new Unsupported('The native PlantUML renderer does not draw '.$symbol.' elements');
        }
        $lines = [\trim((string) $command->get('DESC')), ...\array_map(\trim(...), \array_slice($command->lines, offset: 1))];
        $last = \count($lines) - 1;
        $lines[$last] = \rtrim($lines[$last], characters: ']"');
        self::undeclared($this->elements[(string) $command->get('CODE')] ?? null);
        $element = $this->get((string) $command->get('CODE'));
        $element->symbol = $symbol;
        $element->display = \array_values(\array_filter($lines, static fn (string $line): bool => '' !== $line));
        $element->stereotypes = ClassReader::stereotypes((string) $command->get('STEREO'));
        $element->color = PlantUmlColor::written($command->get('COLOR')) ?? $element->color;
        $this->last = $element->id;
    }

    private function link(PlantUmlCommand $command): void
    {
        if (null !== $command->get('INSIDE')) {
            throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line());
        }
        $renamed = new PlantUmlCommand(
            'CommandLinkClass',
            [
                'ENT1' => [$this->end((string) $command->get('ENT1'))],
                'ENT2' => [$this->end((string) $command->get('ENT2'))],
                'ARROW_HEAD1' => [$command->get('HEAD1')],
                'ARROW_HEAD2' => [$command->get('HEAD2')],
                'ARROW_BODY1' => [$command->get('BODY1')],
                'ARROW_BODY2' => [$command->get('BODY2')],
                'ARROW_DIRECTION' => [$command->get('DIRECTION')],
                'ARROW_STYLE1' => [$command->get('ARROW_STYLE1')],
                'ARROW_STYLE2' => [$command->get('ARROW_STYLE2')],
                'FIRST_LABEL' => [$command->get('FIRST_LABEL')],
                'SECOND_LABEL' => [$command->get('SECOND_LABEL')],
                'LABEL_LINK' => [$command->get('LABEL_LINK')],
                'COLOR' => [$command->get('COLOR')],
            ],
            $command->lines,
        );
        $link = ClassLinks::read($renamed, (string) $renamed->get('ENT1'), (string) $renamed->get('ENT2'));
        $stereotype = ClassReader::stereotypes((string) $command->get('STEREOTYPE'));
        $link->stereotype = $stereotype[0] ?? null;
        $this->links[] = $link;
    }

    /** A link's end: an element by its short form or its name, or a note by its name. */
    private function end(string $text): string
    {
        foreach ($this->notes as $note) {
            if ($note->id === $text && null === $note->entity) {
                return $note->id;
            }
        }
        if (\str_starts_with($text, '()')) {
            $element = $this->get(self::bare(\trim(\substr($text, offset: 2))));
            $element->symbol = 'unknown' === $element->symbol ? 'interface' : $element->symbol;

            return $element->id;
        }
        // As PlantUML links to a container named so.
        $container = self::bare($text);
        if (\array_key_exists($container, $this->groups) && !\array_key_exists($container, $this->elements) && 'together' !== $this->groups[$container]->symbol) {
            return $container;
        }
        $first = \strlen($text) > 2 ? $text[0] : '';
        $business = \str_ends_with($text, '/');
        $element = $this->get(self::bare($text));
        if ('unknown' === $element->symbol && !\array_key_exists($element->id, $this->groups)) {
            $element->symbol = match ($first) {
                '(' => 'usecase',
                ':' => 'actor',
                '[' => 'component',
                default => 'unknown',
            };
            $element->business = $business && \in_array($first, ['(', ':'], strict: true);
        }

        return $element->id;
    }

    /** A container, or a `together` block; its id. */
    private function group(PlantUmlCommand $command): string
    {
        $parent = [] === $this->open ? null : $this->open[\count($this->open) - 1];
        if ('CommandTogether' === $command->name) {
            $id = 'together#'.\count($this->groups);
            $this->groups[$id] = new DescriptionGroup($id, '', 'together', $parent);

            return $id;
        }
        $symbol = \strtolower((string) $command->get('SYMBOL'));
        $display = self::bare($command->lazy('DISPLAY') ?? $command->lazy('CODE') ?? '');
        $id = self::bare($command->lazy('CODE') ?? $display);
        $group = $this->groups[$id] ?? new DescriptionGroup($id, $display, $symbol, $parent);
        $group->stereotypes = ClassReader::stereotypes((string) $command->lazy('STEREOTYPE'));
        $group->color = PlantUmlColor::written($command->get('COLOR')) ?? $group->color;
        $this->groups[$id] = $group;
        unset($this->elements[$id]);

        return $id;
    }

    private function floating(PlantUmlCommand $command): void
    {
        $lines = 'CommandFactoryNote$1' === $command->name ? ClassReader::split((string) $command->get('DISPLAY')) : ActivityReader::columns($command->body());
        $this->notes[] = new PlantUmlNote((string) $command->get('CODE'), $lines, color: $command->get('COLOR'), group: $this->current());
        $this->leaves[] = (string) $command->get('CODE');
    }

    private function beside(PlantUmlCommand $command): void
    {
        $id = $command->get('CODE');
        // As PlantUML, beside an element or a container.
        $element = match (true) {
            null === $id => $this->last,
            \array_key_exists(self::bare($id), $this->groups) => self::bare($id),
            default => $this->get(self::bare($id))->id,
        };
        if (null === $element) {
            throw new PlantUmlRefusal('The PlantUML note has no element to stand beside: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnEntity$1' === $command->name ? ClassReader::split((string) $command->get('NOTE')) : ActivityReader::columns($command->body());
        $this->notes[] = new PlantUmlNote('note#'.\count($this->notes), $lines, $element, \strtolower((string) $command->get('POSITION')), color: $command->get('COLOR'), group: $this->current());
    }

    private function onLink(PlantUmlCommand $command): void
    {
        if ([] === $this->links) {
            throw new PlantUmlRefusal('The PlantUML note has no link to stand on: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnLink$2' === $command->name ? ClassReader::split((string) $command->get('NOTE')) : ActivityReader::columns($command->body());
        $this->notes[] = new PlantUmlNote(
            'note#'.\count($this->notes),
            $lines,
            position: \strtolower($command->get('POSITION') ?? 'bottom'),
            link: \count($this->links) - 1,
            color: $command->get('COLOR'),
        );
    }

    /** Names only links used become actors in a diagram with actors or use cases, interfaces otherwise, as PlantUML settles them. */
    /**
     * `hide stereotype`, `show <<s>> stereotype`, `hide name stereotype`: kept, in order, to apply
     * to every element and group once read; hiding anything else a description diagram does not
     * draw does nothing, as in PlantUML.
     */
    private function hideOrShow(PlantUmlCommand $command): void
    {
        $match = [];
        \preg_match('/^(hide|show)\s+(.*?)\s*(?:empty\s+)?(\w+)$/i', $command->line(), $match);
        if (!\str_starts_with(\strtolower($match[3]), 'stereotype')) {
            return;
        }
        $gender = '' === $match[2] ? null : $match[2];
        if (
            null !== $gender
            && !\str_starts_with($gender, '<<')
            && !\in_array(
                \strtolower($gender),
                ['class', 'object', 'interface', 'enum', 'abstract', 'annotation', 'protocol', 'struct', 'exception', 'metaclass', 'stereotype', 'record', 'dataclass'],
                strict: true,
            )
            && !\array_key_exists(self::bare($gender), $this->elements + $this->groups)
        ) {
            throw new PlantUmlRefusal('No such element '.self::bare($gender));
        }
        $this->stereotypeRules[] = ['show' === \strtolower($match[1]), $gender];
    }

    /**
     * @param string $componentStyle `skinparam componentStyle`, as PlantUML's `ComponentStyle` reads it: `uml1`, `uml2` or `rectangle`
     * @param string $actorStyle     `skinparam actorStyle`, as its `ActorStyle` reads it: `stickman`, `awesome` or `hollow`, a business actor a stick man whatever it says
     */
    private function settle(string $componentStyle, string $actorStyle): void
    {
        foreach ([...$this->elements, ...$this->groups] as $entity) {
            foreach ($this->stereotypeRules as [$show, $gender]) {
                $entity->stereotypeShown = self::is($entity, $gender) ? $show : $entity->stereotypeShown;
            }
        }
        // As PlantUML, a business actor or use case is not one of these.
        $usecase = [] !== \array_filter($this->elements, static fn (DescriptionElement $element): bool => !$element->business && \in_array($element->symbol, ['actor', 'usecase'], strict: true));
        $default = $usecase ? 'actor' : 'interface';
        foreach ($this->elements as $element) {
            $element->symbol = 'unknown' === $element->symbol ? $default : $element->symbol;
            $element->shape = match (true) {
                'component' === $element->symbol => ['uml1' => 'component1', 'rectangle' => 'rectangle'][$componentStyle] ?? null,
                'actor' === $element->symbol && !$element->business => ['awesome' => 'awesome', 'hollow' => 'hollow'][$actorStyle] ?? null,
                default => null,
            };
        }
    }

    /**
     * Refuses a link to a container holding nothing, which PlantUML lays out as an element.
     *
     * @throws Unsupported
     */
    private function linkedContainers(): void
    {
        $held = [];
        foreach ([...$this->elements, ...$this->notes] as $member) {
            $held[$member->group ?? ''] = true;
        }
        foreach ($this->groups as $group) {
            $held[$group->parent ?? ''] = true;
        }
        foreach ($this->links as $link) {
            foreach ([$link->from, $link->to] as $end) {
                if (\array_key_exists($end, $this->groups) && !\array_key_exists($end, $held)) {
                    throw new Unsupported('The native PlantUML renderer does not draw links to empty containers such as '.$end);
                }
            }
        }
    }

    /** Whether a `hide` or `show` means an element or group: all, those of a stereotype, or one by name; never a class's kind. */
    private static function is(DescriptionElement|DescriptionGroup $entity, ?string $gender): bool
    {
        return match (true) {
            null === $gender => true,
            \str_starts_with($gender, '<<') => \implode('', \array_map(static fn (string $stereotype): string => '<<'.$stereotype.'>>', $entity->stereotypes)) === $gender,
            default => self::bare($gender) === $entity->id,
        };
    }

    private function get(string $id, ?string $display = null): DescriptionElement
    {
        if (\str_contains($id, '.') || \str_contains($id, '::')) {
            throw new Unsupported('The native PlantUML renderer does not draw namespaced names such as '.$id);
        }
        if (!\array_key_exists($id, $this->elements) && !\array_key_exists($id, $this->groups)) {
            $this->elements[$id] = new DescriptionElement($id, ClassReader::split($display ?? $id), group: $this->current());
            $this->leaves[] = $id;
        }
        if (\array_key_exists($id, $this->groups)) {
            throw new Unsupported('The native PlantUML renderer does not draw links to containers such as '.$id);
        }
        if (null !== $display) {
            $this->elements[$id]->display = ClassReader::split($display);
        }

        return $this->elements[$id];
    }

    private function current(): ?string
    {
        return [] === $this->open ? null : $this->open[\count($this->open) - 1];
    }

    /** The symbol a short form says: `()` an interface, `(…)` a use case, `:…:` an actor, `[…]` a component. */
    private static function shortForm(string $code, ?string $display): ?string
    {
        if (\str_starts_with($code, '()')) {
            return 'interface';
        }
        $first = ($code[0] ?? '').(null === $display ? '' : $display[0] ?? '');

        return match (true) {
            \str_contains($first, '(') => 'usecase',
            \str_contains($first, ':') => 'actor',
            \str_contains($first, '[') => 'component',
            default => null,
        };
    }

    /** A name without the quotes or the brackets of its short form, and the slash of a business one. */
    private static function bare(string $name): string
    {
        $name = \trim($name);
        $name = \rtrim($name, characters: '/');
        foreach (['"' => '"', '(' => ')', ':' => ':', '[' => ']'] as $open => $close) {
            if (\strlen($name) >= 2 && \str_starts_with($name, $open) && \str_ends_with($name, $close)) {
                return \trim(\substr($name, offset: 1, length: -1));
            }
        }

        return $name;
    }
}
