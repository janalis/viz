<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\UseCase\Actor;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;
use Janalis\Viz\PlantUML\Source\PlantUmlUseCaseWriter;
use Janalis\Viz\Tests\Support\UseCaseSample;
use PHPUnit\Framework\TestCase;

final class PlantUmlUseCaseWriterTest extends TestCase
{
    public function testTheSharedModelIsWrittenAsAPlantUmlUseCaseDiagram(): void
    {
        $this->assertSame(<<<'PUML'
            @startuml
            title Shop
            rectangle "Web shop" {
              usecase "Browse catalog" as Browse
              usecase "Pay" as Pay <<Core>>
              actor/ "Sales 'clerk'" as Clerk <<Employee>>
            }
            actor "Customer" as Customer
            actor "end" as end
            usecase/ "Coupon" as Coupon
            usecase "Audit" as Audit
            Customer --> Browse
            Customer --> Pay : pays\nonline
            Clerk <-- Pay : helps
            Clerk <-- Audit
            Customer --o Audit
            end x-- Audit : x
            Clerk -- Customer
            Clerk *--|> Coupon
            Pay ..> Audit : <<include>>
            Coupon ..> Pay : <<extend>>
            Clerk --|> Customer
            Clerk ..> Pay : <<include>>
            Clerk --|> Pay
            note "Needs\nlogin" as N0
            N0 .. Pay
            note "free" as N1
            @enduml

            PUML, PlantUmlUseCaseWriter::write(UseCaseSample::diagram()));
    }

    public function testLeftToRightTurnsTheLayoutAndAnIdNeverCollides(): void
    {
        $diagram = new UseCaseDiagram([new Actor('u0'), new Actor('a b')], boundaries: [new Boundary('b', members: ['ghost'])], direction: Direction::RightLeft);

        $this->assertSame(
            "@startuml\nleft to right direction\nrectangle \"b\" {\n  usecase \"ghost\" as ghost\n}\nactor \"u0\" as u0\nactor \"a b\" as u1\n@enduml\n",
            PlantUmlUseCaseWriter::write($diagram),
        );
    }
}
