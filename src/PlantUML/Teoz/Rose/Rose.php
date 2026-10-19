<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Sequences\Arrow;
use Janalis\Viz\PlantUML\Sequences\SequenceBox;
use Janalis\Viz\PlantUML\Sequences\SequenceGroup;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Sequences\SequenceParticipant;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Style\StyleSheet;

/**
 * PlantUML's Rose skin for sequence diagrams: each component styled from the document's
 * style sheet, under `sequenceDiagram`, as PlantUML styles it.
 */
final readonly class Rose
{
    private const array NO_PADDING = [0.0, 0.0, 0.0, 0.0];

    public function __construct(
        private StyleSheet $sheet,
    ) {}

    /** @param list<string> $stereotypes */
    public function style(string $name, array $stereotypes = [], string ...$names): Style
    {
        return $this->sheet->style(['root', 'element', 'sequenceDiagram', $name, ...$names], $stereotypes);
    }

    /** The colour of the lines a sequence diagram draws with no style of their own. */
    public function lineColor(): string
    {
        return $this->sheet->style(['root', 'element', 'sequenceDiagram'])->color('LineColor', '#181818');
    }

    /** A participant's head, or its foot when not. */
    public function participant(SequenceParticipant $participant, bool $head): Component
    {
        $style = $this->participantStyle($participant);
        $lines = [...\array_map(static fn (string $stereotype): string => '<i>«'.$stereotype.'»</i>', $participant->stereotypes), ...$participant->display];
        $pen = Pen::of($style);
        $back = $style->color('BackGroundColor', '#e2e2f0');

        return match ($participant->type) {
            'actor', 'boundary', 'control', 'entity', 'database' => new ParticipantIcon(new Icon($participant->type, $pen, $back), RoseText::of($lines, $style, [0.0, 3.0, 0.0, 3.0]), $head),
            'queue' => new ParticipantQueue(RoseText::of($lines, $style, [0.0, 3.0, 0.0, 3.0]), $pen, $back),
            default => new ParticipantBox(
                RoseText::of($lines, $style, $style->sides('Padding')),
                $pen,
                $back,
                $style->number('RoundCorner'),
                $style->sides('Margin'),
                $style->number('MinimumWidth'),
                'collections' === $participant->type ? 4.0 : 0.0,
            ),
        };
    }

    public function lifeLine(SequenceParticipant $participant): Component
    {
        return new LifeLine(Pen::of($this->style('lifeLine', $participant->stereotypes)));
    }

    public function delayLine(): Component
    {
        return new LifeLine(Pen::of($this->style('lifeLine', [], 'delay')));
    }

    /**
     * An activation box, in its own colours if it has them, closed at its ends or open where a delay cuts it.
     *
     * @param array{?string, ?string} $colors its background and line colours
     */
    public function activation(SequenceParticipant $participant, array $colors, bool $closeUp, bool $closeDown): Component
    {
        $style = $this->style('activationBox', $participant->stereotypes);
        $back = PlantUmlColor::parse((string) $colors[0]) ?? $style->color('BackGroundColor', '#ffffff');
        $line = PlantUmlColor::parse((string) $colors[1]) ?? $style->color('LineColor', '#181818');

        return new ActiveLine(new Pen($line), $back, $closeUp, $closeDown);
    }

    public function destroy(): Component
    {
        return new DestroyCross(Pen::of($this->style('lifeLine', [], 'destroy'), '#a80036'));
    }

    /**
     * A message's arrow, or its loop for a message to itself, its label after its number.
     *
     * @param list<string> $lines
     */
    public function arrow(array $lines, ?string $number, ArrowConfig $config, Arrow $arrow): ArrowComponent
    {
        $style = $this->style('arrow');
        $text = RoseText::of($lines, $style, [1.0, 7.0, 1.0, 7.0], $number);
        $pen = Pen::of($style);
        $pen = null === $arrow->color ? $pen : $pen->withColor(PlantUmlColor::parse($arrow->color) ?? $pen->color);
        $back = $style->color('BackGroundColor', '#000000');
        if ($config->self) {
            return new SelfArrow($text, $config, $pen, $back);
        }

        return new MessageArrow($text, $config, $pen, $back, \strtolower($style->value('HorizontalAlignment', 'left')));
    }

    public function note(SequenceNote $note): Component
    {
        $style = 'note' === $note->style ? $this->style('note') : $this->style('note', [], $note->style);
        $back = PlantUmlColor::parse((string) $note->color) ?? $style->color('BackGroundColor', '#feffdd');

        return new NoteShape($note->style, RoseText::of($note->text, $style, NoteShape::padding($note->style)), Pen::of($style), $back, $style->number('RoundCorner'));
    }

    /** @param list<string> $lines */
    public function divider(array $lines): Component
    {
        $style = $this->style('separator');

        return new DividerBar(RoseText::of($lines, $style, [4.0, 4.0, 4.0, 4.0]), Pen::of($style), $style->color('BackGroundColor', '#eeeeee'), $style->number('RoundCorner'));
    }

    /** @param list<string> $lines */
    public function delay(array $lines): Component
    {
        return new DelayText(RoseText::of($lines, $this->style('lifeLine', [], 'delay'), [4.0, 0.0, 4.0, 0.0]));
    }

    /** @param list<string> $lines a reference's text, under its `ref` tab */
    public function reference(array $lines): Component
    {
        $style = $this->style('reference');
        $header = $this->style('referenceHeader');

        return new ReferenceFrame(
            RoseText::of(['ref'], $header, self::NO_PADDING),
            RoseText::of($lines, $style, [4.0, 4.0, 4.0, 4.0]),
            Pen::of($style),
            $style->color('BackGroundColor'),
            Pen::of($header),
            $header->color('BackGroundColor', '#eeeeee'),
            \strtolower($style->value('HorizontalAlignment', 'left')),
        );
    }

    /** A group's frame: its type in the tab, its comment after it, or a `group`'s label alone. */
    public function groupHeader(SequenceGroup $group): Component
    {
        $style = $this->style('group');
        $header = $this->style('groupHeader');
        $tabBack = PlantUmlColor::parse((string) $group->color) ?? $header->color('BackGroundColor', '#eeeeee');
        [$title, $comment] = 'group' === $group->type ? [$group->comment, ''] : [$group->type, $group->comment];

        return new GroupingHeader(
            RoseText::of(self::lines($title), $header, [1.0, 30.0, 1.0, 15.0]),
            '' === $comment ? null : RoseText::of(self::lines('['.$comment.']'), $style, self::NO_PADDING),
            Pen::of($style),
            $style->number('RoundCorner'),
            Pen::of($header),
            $tabBack,
        );
    }

    /** A group's body colour, its own if given. */
    public function groupBack(SequenceGroup $group): string
    {
        return PlantUmlColor::parse((string) $group->background) ?? $this->style('group')->color('BackGroundColor');
    }

    public function groupElse(SequenceGroup $else): Component
    {
        $style = $this->style('group');

        return new GroupingElse(RoseText::of('' === $else->comment ? [''] : self::lines('['.$else->comment.']'), $style, [1.0, 5.0, 1.0, 5.0]), Pen::of($style));
    }

    /** A box around participants, in its own colour if it has one. */
    public function englober(SequenceBox $box): Component
    {
        $style = $this->style('box');
        $back = PlantUmlColor::parse((string) $box->color) ?? $style->color('BackGroundColor', '#dddddd');

        return new Englober(RoseText::of($box->title, $style, [1.0, 3.0, 1.0, 3.0]), Pen::of($style), $back, $style->number('RoundCorner'));
    }

    private function participantStyle(SequenceParticipant $participant): Style
    {
        $style = $this->style($participant->type, $participant->stereotypes);
        $color = PlantUmlColor::parse((string) $participant->color);
        if (null === $color) {
            return $style;
        }

        return new Style([...$style->values, 'BackGroundColor' => $color]);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return ClassReader::split($text);
    }
}
