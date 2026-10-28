<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\LegacyActivities;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Activities\ActivityReader;
use Janalis\Viz\PlantUML\Classes\ClassLinks;
use Janalis\Viz\PlantUML\Classes\ClassPortions;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A legacy activity diagram's commands carried out as PlantUML's legacy activity diagram
 * carries them out: entities found by name wherever they stand or created in the current
 * partition, links from the last entity when no first is written, `if` adding a branch the
 * links after it start from until `else` returns to it, partitions, and notes beside the last
 * entity or on the last link.
 */
final class LegacyActivityReader
{
    /** @var array<string, LegacyEntity> by name, in the order created */
    private array $entities = [];
    /** @var array<string, LegacyGroup> by name */
    private array $groups = [];
    /** @var list<string> the partitions open, innermost last */
    private array $open = [];
    /** @var list<PlantUmlLink> */
    private array $links = [];
    /** @var list<PlantUmlNote> notes on links */
    private array $notes = [];
    /** @var list<string> the branches of the decisions open, innermost last */
    private array $branches = [];
    private ?string $last = null;
    private Direction $direction = Direction::TopBottom;
    private int $sequence = 0;
    /** @var list<array{bool, ?string, bool, string}> the `hide` and `show` rules, in turn */
    private array $visibility = [];

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlLegacyActivity
    {
        $reader = new self();
        foreach ($commands as $command) {
            $reader->execute($command);
        }
        $reader->hide();

        return new PlantUmlLegacyActivity($document, $reader->entities, $reader->groups, $reader->links, $reader->notes, $reader->direction);
    }

    /** Marks the entities `hide` hides, by name, stereotype or `@unlinked`, as PlantUML matches them. */
    private function hide(): void
    {
        $portions = new ClassPortions($this->visibility);
        $linked = [];
        foreach ($this->links as $link) {
            $linked[$link->from] = true;
            $linked[$link->to] = true;
        }
        foreach ($this->entities as $entity) {
            $entity->hidden = $portions->hides($entity->id, ClassReader::stereotypes((string) $entity->stereotype), \array_key_exists($entity->id, $linked));
        }
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            // As PlantUML, whose legacy activities have no circle, members or stereotype to show or hide.
            'CommandNope', 'UBrexCommandFootboxIgnored', 'CommandHideEmptyDescription', 'CommandHideShowByGender' => null,
            'UBrexCommandHideShow2' => $this->visibility[] = [1 === \preg_match('/^show/i', (string) $command->get('COMMAND')), \trim((string) $command->get('WHAT')), false, 'entity'],
            'UBrexCommandRankDir' => $this->direction = \str_starts_with(\strtolower((string) $command->get('DIRECTION')), 'left') ? Direction::LeftRight : Direction::TopBottom,
            'CommandLinkActivity' => $this->link($command),
            'CommandLinkLongActivity' => $this->long($command),
            'UBrexCommandIf' => $this->startIf($command),
            'UBrexCommandElse' => $this->last = $this->branch('No if for this else'),
            'UBrexCommandEndif' => $this->endif(),
            'UBrexCommandPartition' => $this->partition($command),
            'UBrexCommandEndPartition' => [] === $this->open ? throw new PlantUmlRefusal('No partition defined') : \array_pop($this->open),
            'CommandFactoryNoteActivity$1', 'CommandFactoryNoteActivity$2' => $this->note($command),
            'CommandFactoryNoteOnLink$1', 'CommandFactoryNoteOnLink$2' => $this->onLink($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /** `A --> B`: from the first written, or the last entity, to the second, its arrow's length, direction, style and label as written. */
    private function link(PlantUmlCommand $command): void
    {
        self::plain($command);
        $from = $this->entity($command, '') ?? throw new PlantUmlRefusal('No such activity');
        $this->decorate($from, $command->get('STEREOTYPE'), $command->get('BACKCOLOR'));
        $to = $this->entity($command, '2') ?? throw new PlantUmlRefusal('No such activity');
        $this->last = $to->id;
        $this->decorate($to, $command->get('STEREOTYPE2'), $command->get('BACKCOLOR2'));
        $this->arrow($command, $from->id, $to->id);
    }

    /** `A --> "` … `"`: a link to an activity whose text runs over several lines. */
    private function long(PlantUmlCommand $command): void
    {
        self::plain($command);
        $from = $this->entity($command, '') ?? throw new PlantUmlRefusal('No such entity');
        $this->decorate($from, $command->get('STEREOTYPE'), $command->get('BACKCOLOR'));
        $lines = $command->lines;
        $end = [];
        \preg_match(
            '/^[\s\x{a0}]*([^"“”]*)["“”](?:[\s\x{a0}]+as[\s\x{a0}]+([\p{L}\p{N}][\p{L}\p{N}_.]*))?[\s\x{a0}]*(<<.*>>)?[\s\x{a0}]*(?:in[\s\x{a0}]+(["“”][^"“”]+["“”]|\S+))?[\s\x{a0}]*(#\w+)?$/iu',
            \trim($lines[\count($lines) - 1]),
            $end,
        );
        $text = [];
        $first = (string) $command->get('DESC');
        if ('' !== $first) {
            $text[] = $first;
        }
        foreach (\array_slice($lines, offset: 1, length: -1) as $line) {
            $text[] = $line;
        }
        if ('' !== ($end[1] ?? '')) {
            $text[] = $end[1];
        }
        $display = \implode("\n", $text);
        $partition = '' === ($end[4] ?? '') ? null : \trim($end[4], characters: '"“”');
        $to = $this->find('' === ($end[2] ?? '') ? $display : $end[2], 'activity', ClassReader::split($display), $partition);
        $this->last = $to->id;
        $this->decorate($to, '' === ($end[3] ?? '') ? null : $end[3], '' === ($end[5] ?? '') ? null : $end[5]);
        $this->arrow($command, $from->id, $to->id);
    }

    /** `if "test" then`: a branch, linked from the entity written or the last, the test at its end. */
    private function startIf(PlantUmlCommand $command): void
    {
        $from = $this->ifSource($command);
        $code = null === $command->get('IF2') ? $command->get('ASIF1') : null;
        $label = $command->get('IF2') ?? $command->get('IF1');
        $branch = $this->find($code ?? "\0#".++$this->sequence, 'branch', ['']);
        $this->last = $branch->id;
        $this->branches[] = $branch->id;
        $body = (string) $command->get('ARROW_BODY1').(string) $command->get('ARROW_DIRECTION').(string) $command->get('ARROW_BODY2');
        $link = new PlantUmlLink($from->id, $branch->id, LinkDecor::None, LinkDecor::Arrow, null === $command->get('ARROW_BODY1') ? 2 : \strlen(self::cuca($body)) - 1);
        $link->label = $command->get('BRACKET');
        $link->secondLabel = $label;
        ClassLinks::style($link, $command->lazy('ARROW_STYLE'));
        $this->links[] = $link;
    }

    /** The entity an `if` starts from: the start, a bar, a name or a quoted name, or the last. */
    private function ifSource(PlantUmlCommand $command): LegacyEntity
    {
        return match (true) {
            null !== $command->get('STAR') => $this->start(),
            null !== $command->get('CODE') => $this->find((string) $command->get('CODE'), 'activity', ClassReader::split((string) $command->get('CODE'))),
            null !== $command->get('BAR') => $this->find((string) $command->get('BAR'), 'bar', ClassReader::split((string) $command->get('BAR'))),
            null !== $command->get('QUOTED1') => $this->find($command->get('QUOTED2') ?? (string) $command->get('QUOTED1'), 'activity', ClassReader::split((string) $command->get('QUOTED1'))),
            default => $this->entities[$this->last ?? ''] ?? throw new PlantUmlRefusal('No if possible at this point'),
        };
    }

    private function endif(): void
    {
        $this->branch('No if for this endif');
        \array_pop($this->branches);
    }

    /** The branch of the innermost decision open, which needs an entity seen before it. */
    private function branch(string $refusal): string
    {
        if (null === $this->last || [] === $this->branches) {
            throw new PlantUmlRefusal($refusal);
        }

        return $this->branches[\count($this->branches) - 1];
    }

    /** `partition P #color <<stereotype>> {`: the partition the entities after it are created in. */
    private function partition(PlantUmlCommand $command): void
    {
        $name = (string) ($command->get('NAME') ?? $command->get('NAME', 1));
        $parent = $this->open[\count($this->open) - 1] ?? null;
        $this->groups[$name] ??= new LegacyGroup($name, $name, $parent);
        $group = $this->groups[$name];
        $group->color = $command->get('COLOR') ?? $group->color;
        $group->stereotype = $command->get('STEREOTYPE') ?? $group->stereotype;
        $this->open[] = $name;
    }

    /** `note right: text`: a note linked by a dashed line to the last entity, or the start, on the side written. */
    private function note(PlantUmlCommand $command): void
    {
        $lines = 'CommandFactoryNoteActivity$2' === $command->name ? ClassReader::split((string) $command->get('NOTE')) : ActivityReader::columns($command->body());
        $note = $this->find("\0GN".++$this->sequence, 'note', $lines);
        $note->color = $command->get('COLOR');
        $activity = $this->entities[$this->last ?? ''] ?? $this->start();
        $position = \strtolower((string) $command->get('POSITION'));
        if (Direction::LeftRight === $this->direction) {
            $position = match ($position) {
                'left' => 'top',
                'right' => 'bottom',
                'top' => 'left',
                default => 'right',
            };
        }
        [$from, $to, $length] = match ($position) {
            'right' => [$activity->id, $note->id, 1],
            'left' => [$note->id, $activity->id, 1],
            'bottom' => [$activity->id, $note->id, 2],
            default => [$note->id, $activity->id, 2],
        };
        $link = new PlantUmlLink($from, $to, LinkDecor::None, LinkDecor::None, $length);
        $link->dashed = true;
        $this->links[] = $link;
    }

    /** `note on link: text`: a note in the last link's label. */
    private function onLink(PlantUmlCommand $command): void
    {
        if ([] === $this->links) {
            throw new PlantUmlRefusal('The PlantUML note has no link to stand on: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnLink$2' === $command->name ? ClassReader::split((string) $command->get('NOTE')) : ActivityReader::columns($command->body());
        $this->notes[] = new PlantUmlNote(
            "\0note".\count($this->notes),
            $lines,
            position: \strtolower($command->get('POSITION') ?? 'bottom'),
            link: \count($this->links) - 1,
            color: $command->get('COLOR'),
        );
    }

    /** The link a command writes, from one entity to another, reversed when its arrow points left or up. */
    private function arrow(PlantUmlCommand $command, string $from, string $to): void
    {
        $direction = (string) $command->get('ARROW_DIRECTION');
        $body = (string) $command->get('ARROW_BODY1').$direction.(string) $command->get('ARROW_BODY2');
        $length = \str_contains($direction, '*') ? 2 : \strlen(self::cuca($body)) - 1;
        $link = new PlantUmlLink($from, $to, LinkDecor::None, LinkDecor::Arrow, $length);
        $link->dashed = \str_contains((string) $command->get('ARROW_BODY1').(string) $command->get('ARROW_BODY2'), '.');
        $link->norank = \str_contains($direction, '*');
        $link->label = $command->get('BRACKET');
        ClassLinks::style($link, $command->lazy('ARROW_STYLE'));
        $this->links[] = \in_array(self::direction($body), ['left', 'up'], strict: true) ? self::reversed($link) : $link;
    }

    /** As PlantUML's `manageArrowForCuca`, an arrow's body without its words: one dash across, at least two down. */
    private static function cuca(string $body): string
    {
        $direction = self::direction($body);
        $arrow = (string) \preg_replace('/\w*/', replacement: '', subject: \str_replace('=', replace: '-', subject: $body.'>'));
        if (\in_array($direction, ['left', 'right'], strict: true)) {
            $arrow = (string) \preg_replace('/-+/', replacement: '-', subject: $arrow);
        }

        return 2 === \strlen($arrow) && \in_array($direction, ['up', 'down'], strict: true) ? \preg_replace('/-/', replacement: '--', subject: $arrow, limit: 1) ?? $arrow : $arrow;
    }

    /** As PlantUML's `getQueueDirection`, the way an arrow's body points. */
    private static function direction(string $body): string
    {
        $body = \strtolower($body);
        foreach (['left', 'right', 'up', 'down'] as $word) {
            if (\str_contains($body, $word)) {
                return $word;
            }
        }
        foreach (['l' => 'left', 'r' => 'right', 'u' => 'up', 'd' => 'down'] as $letter => $word) {
            if (\str_contains($body, $letter)) {
                return $word;
            }
        }

        return 1 === \strlen($body) ? 'right' : 'down';
    }

    /** A link turned round, as PlantUML's `getInv` turns one: its ends and their marks swapped. */
    private static function reversed(PlantUmlLink $link): PlantUmlLink
    {
        $reversed = $link->inverted();
        $reversed->label = $link->label;
        $reversed->hidden = $link->hidden;
        $reversed->norank = $link->norank;
        $reversed->color = $link->color;
        $reversed->thickness = $link->thickness;

        return $reversed;
    }

    /**
     * One end of a link, by the groups of its side: an inner activity, the start or an end, a
     * name, a bar, a quoted name, or text left unquoted; the last entity for a first left out.
     *
     * @throws Unsupported for an inner activity
     */
    private function entity(PlantUmlCommand $command, string $side): ?LegacyEntity
    {
        if (null !== $command->get('OPENBRACKET'.$side)) {
            throw new Unsupported('The native PlantUML renderer does not draw inner activities');
        }
        $star = $command->get('STAR'.$side);
        if (null !== $star) {
            return '' === $side ? $this->start() : $this->end($command->get('STAR'.$side, 1));
        }
        $partition = $command->get('PARTITION'.$side);
        $partition = null === $partition ? null : \trim($partition, characters: '"“”');
        $code = $command->get('CODE'.$side);
        $bar = $command->get('BAR'.$side);
        $quoted = $command->get('QUOTED'.$side);
        $invisible = $command->get('QUOTED_INVISIBLE'.$side);

        return match (true) {
            null !== $code => $this->find($code, 'activity', ClassReader::split($code), $partition),
            null !== $bar => $this->find($bar, 'bar', ClassReader::split($bar)),
            null !== $quoted => $this->find($command->get('QUOTED'.$side, 1) ?? $quoted, 'activity', ClassReader::split($quoted), $partition),
            null !== $invisible => $this->find($invisible, 'activity', ClassReader::split($invisible), $partition),
            null === $command->get('FIRST'.$side) => $this->entities[$this->last ?? ''] ?? null,
            default => null,
        };
    }

    private function start(): LegacyEntity
    {
        return $this->find('start', 'start', ['start']);
    }

    private function end(?string $id): LegacyEntity
    {
        return $this->find(null === $id ? 'end' : 'end$'.$id, 'end', ['end']);
    }

    /**
     * The entity of a name wherever it stands, or one created in the partition named — else the
     * one open — as PlantUML finds or creates it; the last created.
     *
     * @param list<string> $display
     * @throws Unsupported for a name a partition holds, which PlantUML links to the partition
     */
    private function find(string $name, string $kind, array $display, ?string $partition = null): LegacyEntity
    {
        if (\array_key_exists($name, $this->groups)) {
            throw new Unsupported('The native PlantUML renderer does not draw links to partitions');
        }
        if (\array_key_exists($name, $this->entities)) {
            return $this->entities[$name];
        }
        $group = $this->open[\count($this->open) - 1] ?? null;
        if (null !== $partition) {
            $this->groups[$partition] ??= new LegacyGroup($partition, $partition, $group);
            $group = $partition;
        }
        $this->entities[$name] = new LegacyEntity($name, $kind, $display, $group);
        if ('note' !== $kind) {
            $this->last = $name;
        }

        return $this->entities[$name];
    }

    private function decorate(LegacyEntity $entity, ?string $stereotype, ?string $color): void
    {
        $entity->stereotype = $stereotype ?? $entity->stereotype;
        $entity->color = $color ?? $entity->color;
    }

    /** @throws Unsupported for a URL on a link or an activity */
    private static function plain(PlantUmlCommand $command): void
    {
        if (null !== $command->lazy('URL')) {
            throw new Unsupported('The native PlantUML renderer does not draw URLs: '.$command->line());
        }
    }
}
