<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Activities\PositionedNote;
use Janalis\Viz\PlantUML\Activities\Stereogroup;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\Source\Unsupported;

/**
 * The tiles of an activity diagram's steps, styled by its document, as PlantUML's tile
 * factories make them: actions, circles, diamonds, notes, and one step above the next with
 * the arrow between them.
 */
final readonly class FtileFactory
{
    public FtileStyles $styles;
    public Rainbow $arrow;
    /** How a test is drawn: `inside` a hexagon, above an `empty` diamond, or inside a `square` one. */
    public string $condition;

    /** @throws Unsupported for decisions closed by a line or else-ifs laid out down */
    public function __construct(PlantUmlDocument $document)
    {
        $this->styles = new FtileStyles($document->styles);
        $this->arrow = Rainbow::of($this->styles->arrow());
        $this->condition = match (\strtolower($document->param('conditionstyle', 'inside'))) {
            'diamond' => 'empty',
            'insidediamond', 'foo1' => 'square',
            default => 'inside',
        };
        if ('hline' === \strtolower($document->param('conditionendstyle'))) {
            throw new Unsupported('The native PlantUML renderer does not draw decisions closed by a line');
        }
        if (\in_array(\strtolower($document->pragmas['useverticalif'] ?? ''), ['true', 'on'], strict: true)) {
            throw new Unsupported('The native PlantUML renderer does not draw else-ifs down');
        }
    }

    /** The pen arrows are drawn with. */
    public function pen(): Pen
    {
        return Pen::of($this->styles->arrow());
    }

    /** @param ?list<string> $lines a label on an arrow, none when not written */
    public function label(?array $lines): ?TextBlock
    {
        return null === $lines ? null : new Label($lines, $this->styles->arrow());
    }

    /** @param list<string> $lines */
    public function activity(array $lines, ?string $stereogroup): Ftile
    {
        return new FtileBox($lines, $this->styles->activity($stereogroup), Stereogroup::boxStyle($stereogroup), new LinkRendering());
    }

    /** A start, stop or end. */
    public function circle(string $kind, ?string $stereogroup): Ftile
    {
        return new FtileCircle($kind, $this->styles->circle($kind, $stereogroup));
    }

    public function spot(string $spot, ?string $color): Ftile
    {
        return new FtileCircle('spot', $this->styles->circle('spot'), $spot, $color);
    }

    /**
     * A test's text, as a diamond writes it.
     *
     * @param ?list<string> $lines
     */
    public function test(?array $lines, ?string $stereogroup = null): TextBlock
    {
        if (null === $lines || [] === \array_filter($lines, static fn (string $line): bool => '' !== \trim($line))) {
            return new EmptyBlock();
        }
        $style = $this->styles->diamond($stereogroup);

        return new Label($lines, $style, \strtolower($style->value('HorizontalAlignment', 'left')));
    }

    /** A diamond of a kind, its background as written or its style's. */
    public function diamond(string $kind, ?string $back = null, TextBlock $label = new EmptyBlock(), ?string $stereogroup = null): FtileDiamond
    {
        $style = $this->styles->diamond($stereogroup);

        return new FtileDiamond($kind, Pen::of($style), PlantUmlColor::parse((string) $back) ?? $style->color('BackGroundColor', '#f1f1f1'), $label);
    }

    /** A note, pointing at the step it stands beside unless it floats. */
    public function note(PositionedNote $note): Opale
    {
        $style = $this->noteStyle($note);

        return new Opale(new Label($note->text, $style), $style, $style->color('BackGroundColor', '#fefece'), linked: !$note->floating);
    }

    /** A note, not pointing at anything. */
    public function looseNote(PositionedNote $note): Opale
    {
        $style = $this->noteStyle($note);

        return new Opale(new Label($note->text, $style), $style, $style->color('BackGroundColor', '#fefece'));
    }

    /**
     * A tile with its notes beside it: one pointing at it, several stacked.
     *
     * @param non-empty-list<PositionedNote> $notes
     */
    public function withNotes(Ftile $tile, array $notes): Ftile
    {
        if (1 === \count($notes)) {
            return FtileWithNote::one($tile, $this->note($notes[0]), $notes[0]->position);
        }

        return $this->stacked($tile, $notes);
    }

    /**
     * A tile with its notes beside it, none pointing at it.
     *
     * @param non-empty-list<PositionedNote> $notes
     */
    public function withLooseNotes(Ftile $tile, array $notes): Ftile
    {
        if (1 === \count($notes)) {
            return FtileWithNote::one($tile, $this->looseNote($notes[0]), $notes[0]->position);
        }

        return $this->stacked($tile, $notes);
    }

    /** A note alone in the flow, which goes through it unless it floats. */
    public function noteAlone(PositionedNote $note): Ftile
    {
        return FtileWithNote::alone($this->looseNote($note), !$note->floating);
    }

    /** One tile above another, 35 apart — more for the label on the arrow between them — the arrow drawn when the first leaves. */
    public function assembly(Ftile $tile1, Ftile $tile2): Ftile
    {
        $label = $this->label($tile2->inLink()->display);
        $space = FtileMarged::bottom($tile1, 35 + ($label?->size()[1] ?? 0.0));
        $result = new FtileAssembly($space, $tile2);
        $geometry = $tile1->geometry();
        if (!$geometry->hasOut()) {
            return $result;
        }
        [$x1, $y1] = $result->translateFor($space);
        [$x2, $y2] = $result->translateFor($tile2);
        [$outX, $outY] = $geometry->out();
        [$inX, $inY] = $tile2->geometry()->in();
        $snake = Snake::through($this->arrow->over($tile2->inLink()->color), Snake::arrow('D'), [[$outX + $x1, $outY + $y1], [$inX + $x2, $inY + $y2]])->withLabel($label);
        $result = new FtileDecorate($result, connections: [new Arrows([$snake])]);
        $maxX = $snake->maxX();

        return null !== $label && $result->geometry()->width < $maxX ? FtileMarged::right($result, $maxX) : $result;
    }

    public function decorateIn(Ftile $tile, LinkRendering $link): Ftile
    {
        return new FtileDecorate($tile, in: $link);
    }

    public function decorateOut(Ftile $tile, LinkRendering $link): Ftile
    {
        return new FtileDecorate($tile, out: $link);
    }

    /** @param non-empty-list<PositionedNote> $notes */
    private function stacked(Ftile $tile, array $notes): Ftile
    {
        $left = [];
        $right = [];
        foreach ($notes as $note) {
            'left' === $note->position ? ($left[] = $this->looseNote($note)) : ($right[] = $this->looseNote($note));
        }

        return FtileWithNote::several($tile, $left, $right);
    }

    private function noteStyle(PositionedNote $note): Style
    {
        $style = $this->styles->note();
        $color = PlantUmlColor::parse((string) $note->color);

        return null === $color ? $style : new Style(['BackGroundColor' => $color] + $style->values);
    }
}
