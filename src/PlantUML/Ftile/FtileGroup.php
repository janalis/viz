<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Activities\LinkRendering;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * A frame around steps — `partition` and `group` with their title in a tab, `rectangle`,
 * `card` — 10 clear of them either side, its title above them, wide enough for its title and
 * for whatever they draw past their own width.
 */
final class FtileGroup extends AbstractFtile
{
    private readonly Ftile $inner;
    private ?Geometry $innerGeometry = null;

    public function __construct(
        Ftile $inner,
        private readonly Label $title,
        private readonly Style $style,
        private readonly string $back,
        /** `partition`, `group`, `rectangle` or `card` */
        private readonly string $type,
        private readonly Pen $arrow,
    ) {
        $this->inner = FtileMarged::horizontal($inner, 10, 10);
    }

    #[\Override]
    protected function measure(): Geometry
    {
        $inner = $this->innerGeometry();
        $supp = $this->supp();
        $title = $this->titleHeight();
        $height = $inner->height + $title + 20;

        return new Geometry($inner->width + $supp, $height, $inner->left + ($supp / 2), $inner->inY + $title, null === $inner->outY ? null : $inner->outY + $title);
    }

    #[\Override]
    public function children(): array
    {
        return [$this->inner];
    }

    #[\Override]
    public function translateFor(Ftile $child): array
    {
        return [$this->supp() / 2, $this->titleHeight()];
    }

    #[\Override]
    public function inLink(): LinkRendering
    {
        return $this->inner->inLink();
    }

    #[\Override]
    public function draw(Canvas $canvas): void
    {
        $geometry = $this->geometry();
        $pen = Pen::of($this->style, '#000000');
        $width = $geometry->width;
        [$titleWidth, $titleHeight] = $this->title->size();
        $shapes = match ($this->type) {
            'rectangle' => [
                Shape::of(ShapeKind::Rectangle, [$pen->rectangle(0.0, 0.0, $width, $geometry->height, $this->back, $this->style->number('RoundCorner'))]),
                ...$this->title->shapes(($width - $titleWidth) / 2, 2.0),
            ],
            'card' => [
                Shape::of(ShapeKind::Rectangle, [$pen->rectangle(0.0, 0.0, $width, $geometry->height, $this->back, $this->style->number('RoundCorner'))]),
                Shape::of(ShapeKind::Line, [$pen->line(0.0, $titleHeight + 4, $width, $titleHeight + 4)]),
                ...$this->title->shapes(($width - $titleWidth) / 2, 2.0),
            ],
            default => $this->frame($pen, $width, $geometry->height),
        };
        $canvas->add(...$shapes);
        $this->inner->draw($canvas->moved(...$this->translateFor($this->inner)));
    }

    /** @return list<Shape> a frame with its title in a tab at its top left */
    private function frame(Pen $pen, float $width, float $height): array
    {
        [$titleWidth, $titleHeight] = $this->title->size();
        [$tab, $corner] = 0.0 === $titleWidth ? [$width / 3, 7.0] : [$titleWidth + 10, 10.0];
        $tabHeight = 0.0 === $titleWidth ? 12.0 : $titleHeight + 3;

        return [
            new Shape(ShapeKind::Rectangle, [$pen->rectangle(0.0, 0.0, $width, $height, $this->back, $this->style->number('RoundCorner'))], 0.0, 0.0, $width, $height, ignoreX: true, ignoreY: true),
            Shape::of(ShapeKind::Path, [$pen->path([[$tab, 0.0], [$tab, $tabHeight - $corner], [$tab - $corner, $tabHeight], [0.0, $tabHeight]])], ignoreX: true),
            ...$this->title->shapes(3.0, 1.0),
        ];
    }

    private function titleHeight(): float
    {
        return \max(25.0, $this->title->size()[1] + 20);
    }

    private function supp(): float
    {
        $inner = $this->innerGeometry();

        return \max($inner->width, $this->title->size()[0] + 20, 20.0) - $inner->width;
    }

    /** The inner tiles' size, wider when they draw past it, as PlantUML measures their drawing. */
    private function innerGeometry(): Geometry
    {
        if (null === $this->innerGeometry) {
            $geometry = $this->inner->geometry();
            $canvas = Canvas::create();
            $this->inner->draw($canvas);
            $maxX = 0.0;
            foreach ($canvas->flush($this->arrow) as $shape) {
                $maxX = \max($maxX, $shape->limits()[2]);
            }
            $missing = $maxX - $geometry->width;
            $this->innerGeometry = $missing > 0 ? $geometry->addDim($missing + 5, 0.0) : $geometry;
        }

        return $this->innerGeometry;
    }
}
