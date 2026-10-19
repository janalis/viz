<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Teoz;

use Janalis\Viz\PlantUML\Teoz\Real\PositiveForce;
use Janalis\Viz\PlantUML\Teoz\Real\RealDelta;
use Janalis\Viz\PlantUML\Teoz\Real\RealDeltaLive;
use Janalis\Viz\PlantUML\Teoz\Real\RealImpl;
use Janalis\Viz\PlantUML\Teoz\Real\RealLine;
use Janalis\Viz\PlantUML\Teoz\Real\RealMax;
use Janalis\Viz\PlantUML\Teoz\Real\RealMiddle;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class RealTest extends TestCase
{
    public function testForcesPushRealsApartUntilTheySettle(): void
    {
        $origin = RealImpl::origin();
        $a = $origin->atLeast(10.0);
        $b = $a->atLeast(20.0);
        $c = $origin->atLeast(5.0);
        $c->ensureBiggerThan($b->plus(15.0));
        $origin->compile();

        $this->assertSame([0.0, 10.0, 30.0, 45.0], [$origin->value(), $a->value(), $b->value(), $c->value()]);
        $this->assertSame([0.0, 45.0], [$origin->line->min(), $origin->line->max()]);
    }

    public function testDeltasMoveWhatTheyAreBuiltOn(): void
    {
        $origin = RealImpl::origin();
        $base = $origin->atLeast(0.0);
        $shifted = $base->plus(-5.0);
        $shifted->ensureBiggerThan($origin->plus(20.0));
        $further = $shifted->atLeast(3.0);
        $origin->compile();

        $this->assertSame([25.0, 20.0, 23.0], [$base->value(), $shifted->value(), $further->value()]);
        $moved = $origin->atLeast(0.0)->plus(1.0);
        $this->assertInstanceOf(RealDelta::class, $moved);
        $moved->move(4.0);
        $this->assertSame(5.0, $moved->value(), 'a delta moves what it is built on');
        $pinned = RealMax::of([$origin])->plus(1.0);
        $this->assertInstanceOf(RealDelta::class, $pinned);
        $pinned->move(4.0);
        $this->assertSame(1.0, $pinned->value(), 'and stays put on what cannot move');
        $fixed = $origin->plus(1.0)->plus(1.0);
        $fixed->ensureBiggerThan($origin);
        $this->assertSame(2.0, $fixed->value());
    }

    public function testLiveDeltasReadTheirDistanceAnew(): void
    {
        $origin = RealImpl::origin();
        $margin = 4.0;
        $live = new RealDeltaLive($origin->atLeast(0.0), static function () use (&$margin): float {
            return $margin;
        });
        $live->ensureBiggerThan($origin->plus(10.0));
        $later = $live->atLeast(2.0);
        $origin->compile();

        $this->assertSame([10.0, 12.0], [$live->value(), $later->value()]);
        $margin = 6.0;
        $this->assertSame(12.0, $live->value(), 'read anew');
        new RealDeltaLive($origin, static fn (): float => 1.0)->move(1.0);
        $this->assertSame(1.0, $origin->value());
    }

    public function testMaximaAndMinimaKeepTheirFirstReading(): void
    {
        $origin = RealImpl::origin();
        $a = $origin->atLeast(5.0);
        $b = $origin->atLeast(9.0);
        $max = RealMax::of([$a, $b]);
        $min = RealMax::least([$a, $b]);
        $this->assertSame([9.0, 5.0], [$max->value(), $min->value()]);
        $min->ensureBiggerThan($origin->plus(20.0));
        $origin->compile();

        $this->assertSame([20.0, 20.0], [$a->value(), $b->value()]);
        $this->assertSame([9.0, 5.0], [$max->value(), $min->value()], 'as PlantUML, kept from the first reading');
        $this->assertSame(10.0, $max->plus(1.0)->value());
    }

    public function testMiddlesMoveBothEndsHalfway(): void
    {
        $origin = RealImpl::origin();
        $left = $origin->atLeast(0.0);
        $right = $left->atLeast(10.0);
        $this->assertInstanceOf(RealImpl::class, $left);
        $this->assertInstanceOf(RealImpl::class, $right);
        $middle = new RealMiddle($left, $right);
        $middle->ensureBiggerThan($origin->plus(25.0));
        $origin->compile();

        $this->assertEqualsWithDelta([25.0, 20.0, 30.0], [$middle->value(), $left->value(), $right->value()], 1e-5, "within the solver's tolerance");
    }

    public function testWhatCannotMoveRefuses(): void
    {
        $origin = RealImpl::origin();
        foreach ([
            static fn () => RealMax::of([$origin])->atLeast(1.0),
            static fn () => RealMax::of([$origin])->ensureBiggerThan($origin),
            static fn () => new RealMiddle($origin, $origin)->atLeast(1.0),
        ] as $refused) {
            try {
                $refused();
                $this->fail('Expected a refusal');
            } catch (Unsupported) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testForcesThatNeverSettleAreGivenUp(): void
    {
        $line = new RealLine();
        $a = new RealImpl($line);
        $b = new RealImpl($line);
        $line->addForce(new PositiveForce($a, $b, 1.0));
        $line->addForce(new PositiveForce($b, $a, 1.0));

        $this->expectExceptionObject(new Unsupported('The sequence diagram cannot be laid out: its constraints never settle'));
        $line->compile();
    }
}
