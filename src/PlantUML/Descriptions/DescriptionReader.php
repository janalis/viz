<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Descriptions;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Classes\ClassLinks;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
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
    private Direction $direction = Direction::TopBottom;
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
        $reader->settle();

        return new PlantUmlDescriptions($document, $reader->elements, $reader->groups, $reader->links, $reader->notes, $reader->direction);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandFootboxIgnored', 'CommandHideEmptyDescription' => null,
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
        if (!\in_array($symbol, self::SYMBOLS, strict: true)) {
            throw new Unsupported('The native PlantUML renderer does not draw '.$symbol.' elements');
        }
        $code = \str_starts_with($code, '()') ? \trim(\substr($code, offset: 2)) : $code;
        $element = $this->get(self::bare($code), null === $display ? null : self::bare($display));
        if ('unknown' !== $element->symbol && $element->symbol !== $symbol) {
            throw new PlantUmlRefusal('This element ('.$element->id.') is already defined');
        }
        $element->symbol = $symbol;
        $element->business = $business;
        $element->stereotypes = ClassReader::stereotypes((string) $command->lazy('STEREOTYPE'));
        $element->color = $command->get('COLOR') ?? $command->get('COLOR2') ?? $element->color;
        $this->last = $element->id;
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
        $element = $this->get((string) $command->get('CODE'));
        $element->symbol = $symbol;
        $element->display = \array_values(\array_filter($lines, static fn (string $line): bool => '' !== $line));
        $element->stereotypes = ClassReader::stereotypes((string) $command->get('STEREO'));
        $element->color = $command->get('COLOR') ?? $element->color;
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
        $group->color = $command->get('COLOR') ?? $group->color;
        $this->groups[$id] = $group;
        unset($this->elements[$id]);

        return $id;
    }

    private function floating(PlantUmlCommand $command): void
    {
        $lines = 'CommandFactoryNote$1' === $command->name ? ClassReader::split((string) $command->get('DISPLAY')) : $command->body();
        $this->notes[] = new PlantUmlNote((string) $command->get('CODE'), $lines, color: $command->get('COLOR'), group: $this->current());
    }

    private function beside(PlantUmlCommand $command): void
    {
        $id = $command->get('CODE');
        $element = null === $id ? $this->last : $this->get(self::bare($id))->id;
        if (null === $element) {
            throw new PlantUmlRefusal('The PlantUML note has no element to stand beside: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnEntity$1' === $command->name ? ClassReader::split((string) $command->get('NOTE')) : $command->body();
        $this->notes[] = new PlantUmlNote('note#'.\count($this->notes), $lines, $element, \strtolower((string) $command->get('POSITION')), color: $command->get('COLOR'), group: $this->current());
    }

    private function onLink(PlantUmlCommand $command): void
    {
        if ([] === $this->links) {
            throw new PlantUmlRefusal('The PlantUML note has no link to stand on: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnLink$2' === $command->name ? ClassReader::split((string) $command->get('NOTE')) : $command->body();
        $this->notes[] = new PlantUmlNote(
            'note#'.\count($this->notes),
            $lines,
            position: \strtolower($command->get('POSITION') ?? 'bottom'),
            link: \count($this->links) - 1,
            color: $command->get('COLOR'),
        );
    }

    /** Names only links used become actors in a diagram with actors or use cases, interfaces otherwise, as PlantUML settles them. */
    private function settle(): void
    {
        $usecase = [] !== \array_filter($this->elements, static fn (DescriptionElement $element): bool => \in_array($element->symbol, ['actor', 'usecase'], strict: true));
        $default = $usecase ? 'actor' : 'interface';
        foreach ($this->elements as $element) {
            $element->symbol = 'unknown' === $element->symbol ? $default : $element->symbol;
        }
    }

    private function get(string $id, ?string $display = null): DescriptionElement
    {
        if (\str_contains($id, '.') || \str_contains($id, '::')) {
            throw new Unsupported('The native PlantUML renderer does not draw namespaced names such as '.$id);
        }
        if (!\array_key_exists($id, $this->elements) && !\array_key_exists($id, $this->groups)) {
            $this->elements[$id] = new DescriptionElement($id, ClassReader::split($display ?? $id), group: $this->current());
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
