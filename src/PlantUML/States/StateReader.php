<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Activities\ActivityReader;
use Janalis\Viz\PlantUML\Classes\ClassLinks;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A state diagram's commands carried out as PlantUML's state diagram carries them out:
 * states and composite states with their regions, `[*]` as the start or the end of the
 * scope it is written in, history and synchronization bars, transitions with their arrows,
 * descriptions added with `State : text`, notes, and `hide empty description`.
 */
final class StateReader
{
    private const array KINDS = [
        'choice' => 'choice',
        'fork' => 'fork',
        'join' => 'join',
        'start' => 'start',
        'end' => 'end',
        'history' => 'history',
        'history*' => 'deephistory',
        'entrypoint' => 'entry',
        'exitpoint' => 'exit',
    ];

    /** @var array<string, StateNode> */
    private array $nodes = [];
    /** @var array<string, list<string>> each composite state's region separators, `--` or `||`, by its id */
    private array $composites = [];
    /** @var list<array{string, int}> the composites open around the current line and their current region */
    private array $open = [];
    /** @var list<PlantUmlLink> */
    private array $links = [];
    /** @var list<PlantUmlNote> */
    private array $notes = [];
    private bool $hideEmpty = false;
    private Direction $direction = Direction::TopBottom;
    private ?string $last = null;

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlStates
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

        return new PlantUmlStates($document, $reader->nodes, $reader->composites, $reader->links, $reader->notes, $reader->hideEmpty, $reader->direction);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandFootboxIgnored' => null,
            'CommandRankDir' => $this->direction = \str_starts_with(\strtolower($command->line()), 'left') ? Direction::LeftRight : Direction::TopBottom,
            'CommandHideEmptyDescription' => $this->hideEmpty = 'hide' === \strtolower((string) $command->get('HIDE')),
            'CommandCreateState' => $this->create($command),
            'CommandCreatePackageState' => $this->composite($command),
            'CommandEndState' => \array_pop($this->open),
            'CommandConcurrentState' => $this->region((string) $command->get('TYPE')),
            'CommandAddField' => $this->state((string) ($command->get('CODE3') ?? $command->get('CODE4')))->describe((string) $command->get('FIELD')),
            'CommandLinkState', 'CommandLinkStateReverse' => $this->link($command),
            'CommandFactoryNote$1', 'CommandFactoryNote$2' => $this->floating($command),
            'CommandFactoryNoteOnEntity$1', 'CommandFactoryNoteOnEntity$2' => $this->beside($command),
            'CommandFactoryNoteOnLink$1', 'CommandFactoryNoteOnLink$2' => $this->onLink($command),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    private function create(PlantUmlCommand $command): StateNode
    {
        $state = $this->state((string) $command->lazy('CODE'), $command->lazy('DISPLAY'));
        $stereotypes = ClassReader::stereotypes((string) $command->get('STEREOGROUP'));
        foreach ($stereotypes as $stereotype) {
            $kind = self::KINDS[\strtolower($stereotype)] ?? null;
            if (null === $kind && \in_array(\strtolower($stereotype), ['inputpin', 'outputpin', 'expansioninput', 'expansionoutput', 'sdlreceive'], strict: true)) {
                throw new Unsupported('The native PlantUML renderer does not draw <<'.$stereotype.'>> states');
            }
            $state->kind = $kind ?? $state->kind;
        }
        $state->stereotypes = \array_values(\array_filter($stereotypes, static fn (string $stereotype): bool => !\array_key_exists(\strtolower($stereotype), self::KINDS)));
        $state->color = $command->get('COLOR') ?? $state->color;
        $field = $command->get('ADDFIELD');
        if (null !== $field) {
            $state->describe($field);
        }
        $this->last = $state->id;

        return $state;
    }

    private function composite(PlantUmlCommand $command): void
    {
        $state = $this->create($command);
        $this->composites[$state->id] ??= [];
        $this->open[] = [$state->id, 0];
    }

    private function region(string $separator): void
    {
        if ([] === $this->open) {
            throw new Unsupported('A PlantUML region separator stands outside any composite state');
        }
        $top = \count($this->open) - 1;
        [$id, $region] = $this->open[$top];
        $this->composites[$id][] = $separator;
        $this->open[$top] = [$id, $region + 1];
    }

    private function link(PlantUmlCommand $command): void
    {
        $from = $this->end((string) $command->get('ENT1'), 'start');
        $to = $this->end((string) $command->get('ENT2'), 'end');
        $body = (string) $command->get('ARROW_BODY1').(string) $command->get('ARROW_DIRECTION').(string) $command->get('ARROW_BODY2');
        $direction = ClassLinks::direction($body);
        $length = \in_array($direction, ['left', 'right'], strict: true) ? 1 : \max(1, \strlen((string) \preg_replace('/[^-]/', replacement: '', subject: $body)));
        $link = new PlantUmlLink(
            $from,
            $to,
            null === $command->get('ARROW_CROSS_START') ? LinkDecor::None : LinkDecor::NotNavigable,
            null === $command->get('ARROW_CIRCLE_END') ? LinkDecor::Arrow : LinkDecor::Circle,
            $length,
        );
        $link->label = $command->get('LABEL');
        $link = \in_array($direction, ['left', 'up'], strict: true) ? self::turned($link) : $link;
        ClassLinks::style($link, $command->lazy('ARROW_STYLE'));
        $this->links[] = $link;
    }

    private static function turned(PlantUmlLink $link): PlantUmlLink
    {
        $turned = $link->inverted();
        $turned->label = $link->label;

        return $turned;
    }

    /** A link's end: `[*]` the start or the end of the current scope, `[H]` its history, `X[H]` X's, `==bar==` a bar, or a state. */
    private function end(string $name, string $pseudo): string
    {
        // Each concurrent region is a scope of its own, with its own start and end.
        [$composite, $region] = [] === $this->open ? ['', 0] : $this->open[\count($this->open) - 1];
        $scope = 0 === $region ? $composite : $composite.'#'.$region;
        $match = [];

        return match (true) {
            '[*]' === $name => $this->pseudo('*'.$pseudo.'*'.$scope, $pseudo)->id,
            1 === \preg_match('/^(.*)\[(H\*?)\]$/i', $name, $match) => $this->history($match[1], '[H*]' === \strtoupper('['.$match[2].']') ? 'deephistory' : 'history', $scope),
            1 === \preg_match('/^==+(.+?)==+$/', $name, $match) => $this->pseudo('=='.$match[1], 'fork')->id,
            default => $this->state($name)->id,
        };
    }

    /** `[H]` or `[H*]`: the history of the current scope, or `X[H]` that of X, which stands inside X as PlantUML draws it. */
    private function history(string $owner, string $kind, string $scope): string
    {
        $id = '*'.('history' === $kind ? 'h' : 'h*').'*'.('' === $owner ? $scope : $owner);
        if ('' !== $owner && !\array_key_exists($id, $this->nodes)) {
            $this->state($owner);
            $this->nodes[$id] = new StateNode($id, [], $kind, group: \trim($owner, characters: '"'));
        }

        return $this->pseudo($id, $kind)->id;
    }

    private function pseudo(string $id, string $kind): StateNode
    {
        $state = $this->state($id);
        $state->kind = $kind;
        $state->display = [];

        return $state;
    }

    private function floating(PlantUmlCommand $command): void
    {
        $lines = 'CommandFactoryNote$1' === $command->name ? ClassReader::split((string) $command->get('DISPLAY')) : ActivityReader::columns($command->body());
        $this->notes[] = new PlantUmlNote((string) $command->get('CODE'), $lines, color: $command->get('COLOR'), group: $this->current());
    }

    private function beside(PlantUmlCommand $command): void
    {
        $id = $command->get('CODE');
        $state = null === $id ? $this->last : $this->state($id)->id;
        if (null === $state) {
            throw new PlantUmlRefusal('The PlantUML note has no state to stand beside: '.$command->line());
        }
        $lines = 'CommandFactoryNoteOnEntity$1' === $command->name ? ClassReader::split((string) $command->get('NOTE')) : ActivityReader::columns($command->body());
        $this->notes[] = new PlantUmlNote(
            'note#'.\count($this->notes),
            $lines,
            $state,
            \strtolower((string) $command->get('POSITION')),
            color: $command->get('COLOR'),
            group: $this->nodes[$state]->group,
        );
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

    /** A state by name, made in the current composite state and region when new. */
    private function state(string $name, ?string $display = null): StateNode
    {
        $id = \trim($name, characters: '"');
        if (\str_contains($id, '.') || \str_contains($id, '::')) {
            throw new Unsupported('The native PlantUML renderer does not draw namespaced names such as '.$id);
        }
        if (!\array_key_exists($id, $this->nodes)) {
            [$group, $region] = [] === $this->open ? [null, 0] : $this->open[\count($this->open) - 1];
            $this->nodes[$id] = new StateNode($id, ClassReader::split($display ?? $id), group: $group, region: $region);
        }
        if (null !== $display) {
            $this->nodes[$id]->display = ClassReader::split($display);
        }

        return $this->nodes[$id];
    }

    private function current(): ?string
    {
        return [] === $this->open ? null : $this->open[\count($this->open) - 1][0];
    }
}
